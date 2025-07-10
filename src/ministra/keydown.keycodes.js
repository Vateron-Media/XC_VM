var key = {

    POWER: 2085, // Alt+U
    MUTE: 2192,
    MIC: 2032,
    CLOCK: 2032,
    NUM1: 49,
    NUM2: 50,
    NUM3: 51,
    NUM4: 52,
    NUM5: 53,
    NUM6: 54,
    NUM7: 55,
    NUM8: 56,
    NUM9: 57,
    NUM0: 48,
    MENU: 122, // F11
    BACK: 8, // Backspace
    CHANNEL_PREV: 1009, // Shift+Tab
    CHANNEL_NEXT: 9, // Tab
    EXIT: 27, // Esc
    REFRESH: 116, // F5
    DELETE: 116, // F5
    UP: 38, // UP ARROW
    DOWN: 40, // DOWN ARROW
    LEFT: 37, // LEFT ARROW
    RIGHT: 39, // RIGHT ARROW
    OK: 13, // Enter
    PAGE_NEXT: 34, // Page Down
    PAGE_PREV: 33, // Page Up
    NEXT: 34, // Page Down
    PREV: 33, // Page Up
    VOL_UP: 107, // NUMPAD +
    VOL_DOWN: 109, // NUMPAD -
    RED: 112, // F1
    GREEN: 113, // F2
    YELLOW: 114, // F3
    BLUE: 115, // F4
    SERVICES: 120, // F9
    TV: 121,  // F10
    PHONE: 119, // F8
    EPG: 119, // F8
    WEB: 123, // F12
    APP: 123, // F12
    REW: 2066, // Alt+B
    FFWD: 2070, // Alt+F
    PLAY: 2082, // Alt+R
    PAUSE: 2082, // Alt+R
    CONTINUE: 2082, // Alt+R
    STOP: 2083, // Alt+S
    REC: 2087, // Alt+W
    INFO: 2089, // Alt+Y
    FRAME: 117,  // F6
    AUDIO: 2071, // Alt+G
    NULL: 2076, // Alt+L
    KEYBOARD: 2076, // Alt+L
    USB_UNMOUNTED: 2081, // Alt+Q
    USB_MOUNTED: 2080  // Alt+P
};

if (typeof (gSTB) != 'undefined' && gSTB.IsEmulator) {
    key.EXIT = key.BACK;
    key.BACK = key.DELETE;
}