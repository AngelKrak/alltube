<?php
/**
 * VideoDownload class.
 */

namespace Alltube;

use Symfony\Component\Process\ProcessBuilder;

/**
 * Extract info about videos.
 */
class VideoDownload
{
    /**
     * Config instance.
     *
     * @var Config
     */
    private $config;

    /**
     * ProcessBuilder instance used to call Python.
     *
     * @var ProcessBuilder
     */
    private $procBuilder;

    /**
     * VideoDownload constructor.
     */
    public function __construct(Config $config = null)
    {
        if (isset($config)) {
            $this->config = $config;
        } else {
            $this->config = Config::getInstance();
        }
        $this->procBuilder = new ProcessBuilder();
        if (!is_file($this->config->youtubedl)) {
            throw new \Exception("Can't find youtube-dl at ".$this->config->youtubedl);
        } elseif (!is_file($this->config->python)) {
            throw new \Exception("Can't find Python at ".$this->config->python);
        }
        $this->procBuilder->setPrefix(
            array_merge(
                [$this->config->python, $this->config->youtubedl],
                $this->config->params
            )
        );
    }

    /**
     * List all extractors.
     *
     * @return string[] Extractors
     * */
    public function listExtractors()
    {
        return explode(PHP_EOL, trim($this->getProp(null, null, 'list-extractors')));
    }

    /**
     * Get a property from youtube-dl.
     *
     * @param string $url      URL to parse
     * @param string $format   Format
     * @param string $prop     Property
     * @param string $password Video password
     *
     * @return string
     */
    private function getProp($url, $format = null, $prop = 'dump-json', $password = null)
    {
        $this->procBuilder->setArguments(
            [
                '--'.$prop,
                $url,
            ]
        );
        if (isset($format)) {
            $this->procBuilder->add('-f '.$format);
        }
        if (isset($password)) {
            $this->procBuilder->add('--video-password');
            $this->procBuilder->add($password);
        }
        $process = $this->procBuilder->getProcess();
        $process->run();
        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            if ($errorOutput == 'ERROR: This video is protected by a password, use the --video-password option') {
                throw new PasswordException($errorOutput);
            } elseif (substr($errorOutput, 0, 21) == 'ERROR: Wrong password') {
                throw new \Exception('Wrong password');
            } else {
                throw new \Exception($errorOutput);
            }
        } else {
            return trim($process->getOutput());
        }
    }

    /**
     * Get all information about a video.
     *
     * @param string $url      URL of page
     * @param string $format   Format to use for the video
     * @param string $password Video password
     *
     * @return object Decoded JSON
     * */
    public function getJSON($url, $format = null, $password = null)
    {
        return json_decode($this->getProp($url, $format, 'dump-single-json', $password));
    }

    /**
     * Get URL of video from URL of page.
     *
     * It generally returns only one URL.
     * But it can return two URLs when multiple formats are specified
     * (eg. bestvideo+bestaudio).
     *
     * @param string $url      URL of page
     * @param string $format   Format to use for the video
     * @param string $password Video password
     *
     * @return string[] URLs of video
     * */
    public function getURL($url, $format = null, $password = null)
    {
        return explode(PHP_EOL, $this->getProp($url, $format, 'get-url', $password));
    }

    /**
     * Get filename of video file from URL of page.
     *
     * @param string $url      URL of page
     * @param string $format   Format to use for the video
     * @param string $password Video password
     *
     * @return string Filename of extracted video
     * */
    public function getFilename($url, $format = null, $password = null)
    {
        return trim($this->getProp($url, $format, 'get-filename', $password));
    }

    /**
     * Get filename of video with the specified extension.
     *
     * @param string $extension New file extension
     * @param string $url       URL of page
     * @param string $format    Format to use for the video
     * @param string $password  Video password
     *
     * @return string Filename of extracted video with specified extension
     */
    public function getFileNameWithExtension($extension, $url, $format = null, $password = null)
    {
        return html_entity_decode(
            pathinfo(
                $this->getFilename($url, $format, $password),
                PATHINFO_FILENAME
            ).'.'.$extension,
            ENT_COMPAT,
            'ISO-8859-1'
        );
    }

    /**
     * Get filename of audio from URL of page.
     *
     * @param string $url      URL of page
     * @param string $format   Format to use for the video
     * @param string $password Video password
     *
     * @return string Filename of converted audio file
     * */
    public function getAudioFilename($url, $format = null, $password = null)
    {
        return $this->getFileNameWithExtension('mp3', $url, $format, $password);
    }

    /**
     * Add options to a process builder running rtmp.
     *
     * @param ProcessBuilder $builder Process builder
     * @param object         $video   Video object returned by youtube-dl
     *
     * @return ProcessBuilder
     */
    private function addOptionsToRtmpProcess(ProcessBuilder $builder, $video, $avconv = false)
    {
        if ($avconv) {
            $properties = [
                'url'           => '-rtmp_tcurl',
                'webpage_url'   => '-rtmp_pageurl',
                'player_url'    => '-rtmp_swfverify',
                'flash_version' => '-rtmp_flashver',
                'play_path'     => '-rtmp_playpath',
                'app'           => '-rtmp_app',
            ];
        } else {
            $properties = [
                'url'           => '--rtmp',
                'webpage_url'   => '--pageUrl',
                'player_url'    => '--swfVfy',
                'flash_version' => '--flashVer',
                'play_path'     => '--playpath',
                'app'           => '--app',
            ];
        }
        foreach ($properties as $property => $option) {
            if (isset($video->{$property})) {
                $builder->add($option);
                $builder->add($video->{$property});
            }
        }
        if (isset($video->rtmp_conn)) {
            foreach ($video->rtmp_conn as $conn) {
                if ($avconv) {
                    $builder->add('-rtmp_conn');
                } else {
                    $builder->add('--conn');
                }
                $builder->add($conn);
            }
        }

        return $builder;
    }

    /**
     * Get a process that runs rtmp in order to download a video.
     *
     * @param object $video Video object returned by youtube-dl
     *
     * @return \Symfony\Component\Process\Process Process
     */
    private function getRtmpProcess($video)
    {
        if (!shell_exec('which '.$this->config->rtmpdump)) {
            throw(new \Exception('Can\'t find rtmpdump'));
        }
        $builder = new ProcessBuilder(
            [
                $this->config->rtmpdump,
                '-q',
            ]
        );
        $builder = $this->addOptionsToRtmpProcess($builder, $video);

        return $builder->getProcess();
    }

    /**
     * Get a process that runs avconv in order to convert a video to MP3.
     *
     * @param string $url URL of the video file
     *
     * @return \Symfony\Component\Process\Process Process
     */
    private function getAvconvMp3Process($video, $rtmp = false)
    {
        if (!shell_exec('which '.$this->config->avconv)) {
            throw(new \Exception('Can\'t find avconv or ffmpeg'));
        }

        $builder = ProcessBuilder::create(
            [
                $this->config->avconv,
                '-v', 'quiet',
                //Vimeo needs a correct user-agent
                '-user-agent', $this->getProp(null, null, 'dump-user-agent'),
                '-i', $video->url,
                '-f', 'mp3',
                '-vn',
                'pipe:1',
            ]
        );
        if ($rtmp) {
            $builder = $this->addOptionsToRtmpProcess($builder, $video, true);
        }

        return $builder->getProcess();
    }

    /**
     * Get audio stream of converted video.
     *
     * @param string $url      URL of page
     * @param string $format   Format to use for the video
     * @param string $password Video password
     *
     * @return resource popen stream
     */
    public function getAudioStream($url, $format, $password = null)
    {
        $video = $this->getJSON($url, $format, $password);
        if (in_array($video->protocol, ['m3u8', 'm3u8_native'])) {
            throw(new \Exception('Conversion of M3U8 files is not supported.'));
        }

        if (parse_url($video->url, PHP_URL_SCHEME) == 'rtmp') {
            $avconvProc = $this->getAvconvMp3Process($video, true);
            dump($avconvProc->getCommandLine());
            die;

            return popen($chain->getProcess()->getCommandLine(), 'r');
        } else {
            $avconvProc = $this->getAvconvMp3Process($video->url);

            return popen($avconvProc->getCommandLine(), 'r');
        }
    }

    /**
     * Get video stream from an M3U playlist.
     *
     * @param \stdClass $video Video object returned by getJSON
     *
     * @return resource popen stream
     */
    public function getM3uStream(\stdClass $video)
    {
        if (!shell_exec('which '.$this->config->avconv)) {
            throw(new \Exception('Can\'t find avconv or ffmpeg'));
        }

        $procBuilder = ProcessBuilder::create(
            [
                $this->config->avconv,
                '-v', 'quiet',
                '-i', $video->url,
                '-f', $video->ext,
                '-c', 'copy',
                '-bsf:a', 'aac_adtstoasc',
                '-movflags', 'frag_keyframe+empty_moov',
                'pipe:1',
            ]
        );

        return popen($procBuilder->getProcess()->getCommandLine(), 'r');
    }

    /**
     * Get an avconv stream to remux audio and video.
     *
     * @param array $urls URLs of the video ($urls[0]) and audio ($urls[1]) files
     *
     * @return resource popen stream
     */
    public function getRemuxStream(array $urls)
    {
        $procBuilder = ProcessBuilder::create(
            [
                $this->config->avconv,
                '-v', 'quiet',
                '-i', $urls[0],
                '-i', $urls[1],
                '-c', 'copy',
                '-map', '0:v:0 ',
                '-map', '1:a:0',
                '-f', 'matroska',
                'pipe:1',
            ]
        );

        return popen($procBuilder->getProcess()->getCommandLine(), 'r');
    }

    /**
     * Get video stream from an RTMP video.
     *
     * @param \stdClass $video Video object returned by getJSON
     *
     * @return resource popen stream
     */
    public function getRtmpStream(\stdClass $video)
    {
        return popen($this->getRtmpProcess($video)->getCommandLine(), 'r');
    }

    /**
     * Get a Tar stream containing every video in the playlist piped through the server.
     *
     * @param object $video  Video object returned by youtube-dl
     * @param string $format Requested format
     *
     * @return Response HTTP response
     */
    public function getPlaylistArchiveStream($video, $format)
    {
        $playlistItems = [];
        foreach ($video->entries as $entry) {
            $playlistItems[] = urlencode($entry->url);
        }
        $stream = fopen('playlist://'.implode(';', $playlistItems).'/'.$format, 'r');

        return $stream;
    }
}
