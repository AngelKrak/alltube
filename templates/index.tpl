{include file='inc/head.tpl'}
{include file='inc/header.tpl'}
<div class="main">
    <div><img class="logo" src="{base_url|noscheme}/img/logo.png"
    alt="AllTube Download" width="328" height="284"></div>
    <form action="{path_for name="video"}">
    <label class="labelurl" for="url">
        Copy here the URL of your video (Youtube, Dailymotion, etc.)
    </label>
    <div class="champs">
        <span class="URLinput_wrapper">
        <input class="URLinput" type="url" name="url" id="url"
        required autofocus placeholder="http://example.com/video" />
        </span>
        {if $uglyUrls}
            <input type="hidden" name="page" value="video" />
        {/if}
        <input class="downloadBtn" type="submit" value="Download" /><br/>
        {if $convert}
            <div class="mp3">
                <p><input type="checkbox" id="audio" class="audio" name="audio">
                <label for="audio"><span class="ui"></span>
                    Audio only (MP3)</label></p>
            </div>
        {/if}
        <label for="country">Spoof country</label>
        <select name="country" id="country">
            <option></option>
            <option value="fr">France</option>
            <option value="uk">United Kingdom</option>
        </select>
    </div>
    </form>
    <a class="combatiblelink" href="{path_for name="extractors"}">See all supported websites</a>
    <div id="bookmarklet" class="bookmarklet_wrapper">
        <p> Drag this to your bookmarks bar: </p>
        <a class="bookmarklet" href="javascript:window.location='{$domain}{path_for name='video'}?url='+encodeURIComponent(location.href);">Bookmarklet</a>
    </div>

</div>
{include file='inc/footer.tpl'}
