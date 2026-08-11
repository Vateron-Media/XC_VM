/**
 * Scrollbar constructor.
 * @constructor
 */

function scrollbar(parent, for_dom_obj, options) {

    this.for_dom_obj = for_dom_obj;
    this.dom_obj = {};
    this.parent = parent;

    this.scroll_button = {};
    this.height = for_dom_obj.offsetHeight - 35;

    /*if (options){
        if (options.height){
            this.height = options.height;
        }
    }*/

    this.init();
}

scrollbar.prototype.init = function () {
    _debug('scrollbar.init');

    this.dom_obj = create_block_element('mb_scroll', this.parent);

    var scroll_container = create_block_element('mb_scroll_t', this.dom_obj);

    scroll_container.style.height = this.height + 'px';

    this.scroll_button = create_block_element('mb_scroll_c', scroll_container);

    create_block_element('mb_scroll_b', this.dom_obj);
};

scrollbar.prototype.reset = function () {
    _debug('scrollbar.reset');

    this.scroll_button.style.top = 6 + 'px';
};

scrollbar.prototype.refresh = function () {
    _debug('scrollbar.refresh');

    //this.parent.scrollTop
    //this.parent.scrollHeight
    //this.parent.style.height
    var top = 6;
    var input_height = this.for_dom_obj.scrollHeight - parseInt(this.for_dom_obj.offsetHeight);

    _debug('this.for_dom_obj.scrollHeight', this.for_dom_obj.scrollHeight);
    _debug('this.for_dom_obj.offsetHeight', this.for_dom_obj.offsetHeight);
    _debug('input_height', input_height);
    _debug('this.height', this.height);
    _debug('this.parent.scrollTop', this.parent.scrollTop);

    if (input_height != 0) {
        top = (this.for_dom_obj.scrollTop * (this.height - 32)) / input_height + 6;
    }

    _debug('top', top);

    this.scroll_button.style.top = top + 'px';
};

scrollbar.prototype.render = function () {
    _debug('scrollbar.refresh');

    if (this.for_dom_obj.offsetHeight == this.for_dom_obj.scrollHeight) {
        this.dom_obj.hide();
    } else {
        this.dom_obj.show();
    }
};

loader.next();