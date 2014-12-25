/**
 * Created by anton on 12/23/14.
 */

function AwsPurgeAjax() {

    var apbox = jQuery('#message').append('<p id="purge-info" class="spinner" style="display: block; float: none; width: auto"></p>');
    var apbox_info = apbox.find('#purge-info');

    apbox_info.text('Processing purge requests...');
    apbox_info.animate({
        'background-position-x': '180px'
    }, 5000);

    jQuery.ajax({
            url: ajaxurl,
//            cache: false,
//            type: 'POST',
            data: {action: 'awspurgeajax'},
            dataType: "json",
            // context: document.body,
            success: function (data) {
                apbox_info.slideUp(500, function(){
                    apbox_info.text('Done. ' + data.processed + ' URLs purged. Content changes are now visible');
                    apbox_info.removeClass('spinner');
                    apbox_info.slideDown('fast');
                });
            },
            error: function (request) {
                console.log(request);
                apbox_info.slideUp(500, function(){
                    apbox_info.text('Oops, error occured while purging');
                    apbox_info.removeClass('spinner');
                    apbox_info.slideDown('fast');
                });
            }
        }
    );
}

jQuery(document).ready(function () {
    AwsPurgeAjax();
});