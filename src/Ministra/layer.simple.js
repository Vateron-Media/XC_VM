/**
 * Simple Layer constructor.
 * @constructor
 */

function SimpleLayer() {

    this.dom_obj = this.create_block();
    document.body.appendChild(this.dom_obj);

    this.container = document.createElement('div');
    this.container.addClass('simple_layer');
    this.dom_obj.appendChild(this.container);

    this.base_layer = BaseLayer.prototype;
}

SimpleLayer.prototype = new BaseLayer();

function Scrollable(dom_obj, parent) {
    this.dom_obj = dom_obj;
    this.parent = parent;
    this.initScrollbar();
}

Scrollable.prototype.initScrollbar = function () {
    this.scrollbar = new scrollbar(this.parent, this.dom_obj);
};

Scrollable.prototype.scrollTop = function () {
    this.dom_obj.scrollTop = 0;
    this.scrollbar.refresh();
}

Scrollable.prototype.scroll = function (dir) {

    if (dir > 0) {
        this.dom_obj.scrollTop = this.dom_obj.scrollTop + 40;
    } else {
        this.dom_obj.scrollTop = this.dom_obj.scrollTop - 40;
    }

    this.scrollbar.refresh();
};

Scrollable.prototype.scrollPage = function (dir) {

    if (dir > 0) {
        this.dom_obj.scrollTop = this.dom_obj.scrollTop + 200;
    } else {
        this.dom_obj.scrollTop = this.dom_obj.scrollTop - 200;
    }

    this.scrollbar.refresh();
};

loader.next();