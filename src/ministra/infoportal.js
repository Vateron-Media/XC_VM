/**
 * Infoportal modile.
 */

(function () {

    var submenu = module.infoportal_sub || [];

    main_menu.add(word['infoportal_title'], submenu, 'mm_ico_info.png', '', { "layer_name": "infoportal" });

})();

loader.next();