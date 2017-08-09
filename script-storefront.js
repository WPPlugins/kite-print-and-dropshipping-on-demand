var wookite_side = "front", wookite_variant_id = null;
var wookite_product_images = {};

(function($) {
    $(function() {
        if ($('#wookite_front_back_picker').length == 0) {
            // No buttons to play with
            return;
        }
        wookite_product_images = {
            front: wookite_get_side_images('#wookite_front_side'),
            back: wookite_get_side_images('#wookite_back_side'),
        };
        wookite_variant_id = Object.keys(wookite_product_images['front'])[0];
        $('form.variations_form.cart').on('found_variation', function(e, variant) {
            wookite_variant_id = variant['variation_id'];
            if (wookite_side == "back") {
                wookite_variant_side("back", "front");
            } else {
                wookite_variant_side("front", "back");
            }
        });
        $('#wookite_front_side').click(function(e) {
            e.preventDefault();
            wookite_variant_side("front", "back");
        });
        $('#wookite_back_side').click(function(e) {
            e.preventDefault();
            wookite_variant_side("back", "front");
        });
    });

    function wookite_variant_side(active, inactive) {
        wookite_side = active;
        $('#wookite_' + active + '_side').addClass('active').removeClass('inactive');
        $('#wookite_' + inactive + '_side').addClass('inactive').removeClass('active');
        var img = wookite_curr_var_img(wookite_product_images[active]);
        wookite_swap_imgs([
            [/https?:\/\/.*?size=(\d+x\d+)?/g, img + '$1'],
        ]);
    }

    function wookite_curr_var_img(dict) {
        if (wookite_variant_id) {
            return (wookite_variant_id in dict ? dict[wookite_variant_id] : '');
        } else {
            return '';
        }
    }

    function wookite_get_side_images(selector) {
        return $(selector).data('images');
    }

    function wookite_swap_imgs(replacements) {
        var fields = [
            {selector: 'a', fields: ['href', 'data-o_href']},
            {selector: 'img', fields: ['src', 'srcset']},
        ];
        for (var i = 0; i < fields.length; i++) {
            $(fields[i]['selector']).each(function() {
                var el_fields = fields[i].fields;
                for (var j = 0; j < el_fields.length; j++) {
                    var value = $(this).attr(el_fields[j]);
                    for (var k = 0; k < replacements.length; k++)
                        if (value)
                            value = value.replace(replacements[k][0], replacements[k][1]);
                    $(this).attr(fields[i].fields[j], value);
                }
            });
        }
    }

})(jQuery);

