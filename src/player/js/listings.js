window.XC_VM = window.XC_VM || {},
    function (e, t) {
        "object" == typeof exports && "object" == typeof module ? module.exports = t() : "function" == typeof define && define.amd ? define(t) : "object" == typeof exports ? exports.Handlebars = t() : e.Handlebars = t()
    }(this, function () {
        return function (e) {
            function t(r) {
                if (n[r]) return n[r].exports;
                var i = n[r] = {
                    exports: {},
                    id: r,
                    loaded: !1
                };
                return e[r].call(i.exports, i, i.exports, t), i.loaded = !0, i.exports
            }
            var n = {};
            return t.m = e, t.c = n, t.p = "", t(0)
        }([function (e, t, n) {
            "use strict";

            function r() {
                var e = new s.HandlebarsEnvironment;
                return p.extend(e, s), e.SafeString = u["default"], e.Exception = f["default"], e.Utils = p, e.escapeExpression = p.escapeExpression, e.VM = m, e.template = function (t) {
                    return m.template(t, e)
                }, e
            }
            var i = n(7)["default"],
                o = n(8)["default"];
            t.__esModule = !0;
            var a = n(1),
                s = i(a),
                l = n(2),
                u = o(l),
                c = n(3),
                f = o(c),
                d = n(4),
                p = i(d),
                h = n(5),
                m = i(h),
                g = n(6),
                y = o(g),
                v = r();
            v.create = r, y["default"](v), v["default"] = v, t["default"] = v, e.exports = t["default"]
        }, function (e, t, n) {
            "use strict";

            function r(e, t) {
                this.helpers = e || {}, this.partials = t || {}, i(this)
            }

            function i(e) {
                e.registerHelper("helperMissing", function () {
                    if (1 !== arguments.length) throw new f["default"]('Missing helper: "' + arguments[arguments.length - 1].name + '"')
                }), e.registerHelper("blockHelperMissing", function (t, n) {
                    var r = n.inverse,
                        i = n.fn;
                    if (t === !0) return i(this);
                    if (t === !1 || null == t) return r(this);
                    if (m(t)) return t.length > 0 ? (n.ids && (n.ids = [n.name]), e.helpers.each(t, n)) : r(this);
                    if (n.data && n.ids) {
                        var a = o(n.data);
                        a.contextPath = u.appendContextPath(n.data.contextPath, n.name), n = {
                            data: a
                        }
                    }
                    return i(t, n)
                }), e.registerHelper("each", function (e, t) {
                    function n(t, n, i) {
                        l && (l.key = t, l.index = n, l.first = 0 === n, l.last = !!i, c && (l.contextPath = c + t)), s += r(e[t], {
                            data: l,
                            blockParams: u.blockParams([e[t], t], [c + t, null])
                        })
                    }
                    if (!t) throw new f["default"]("Must pass iterator to #each");
                    var r = t.fn,
                        i = t.inverse,
                        a = 0,
                        s = "",
                        l = void 0,
                        c = void 0;
                    if (t.data && t.ids && (c = u.appendContextPath(t.data.contextPath, t.ids[0]) + "."), g(e) && (e = e.call(this)), t.data && (l = o(t.data)), e && "object" == typeof e)
                        if (m(e))
                            for (var d = e.length; a < d; a++) n(a, a, a === e.length - 1);
                        else {
                            var p = void 0;
                            for (var h in e) e.hasOwnProperty(h) && (p && n(p, a - 1), p = h, a++);
                            p && n(p, a - 1, !0)
                        } return 0 === a && (s = i(this)), s
                }), e.registerHelper("if", function (e, t) {
                    return g(e) && (e = e.call(this)), !t.hash.includeZero && !e || u.isEmpty(e) ? t.inverse(this) : t.fn(this)
                }), e.registerHelper("unless", function (t, n) {
                    return e.helpers["if"].call(this, t, {
                        fn: n.inverse,
                        inverse: n.fn,
                        hash: n.hash
                    })
                }), e.registerHelper("with", function (e, t) {
                    g(e) && (e = e.call(this));
                    var n = t.fn;
                    if (u.isEmpty(e)) return t.inverse(this);
                    if (t.data && t.ids) {
                        var r = o(t.data);
                        r.contextPath = u.appendContextPath(t.data.contextPath, t.ids[0]), t = {
                            data: r
                        }
                    }
                    return n(e, t)
                }), e.registerHelper("log", function (t, n) {
                    var r = n.data && null != n.data.level ? parseInt(n.data.level, 10) : 1;
                    e.log(r, t)
                }), e.registerHelper("lookup", function (e, t) {
                    return e && e[t]
                })
            }

            function o(e) {
                var t = u.extend({}, e);
                return t._parent = e, t
            }
            var a = n(7)["default"],
                s = n(8)["default"];
            t.__esModule = !0, t.HandlebarsEnvironment = r, t.createFrame = o;
            var l = n(4),
                u = a(l),
                c = n(3),
                f = s(c),
                d = "3.0.1";
            t.VERSION = d;
            var p = 6;
            t.COMPILER_REVISION = p;
            var h = {
                1: "<= 1.0.rc.2",
                2: "== 1.0.0-rc.3",
                3: "== 1.0.0-rc.4",
                4: "== 1.x.x",
                5: "== 2.0.0-alpha.x",
                6: ">= 2.0.0-beta.1"
            };
            t.REVISION_CHANGES = h;
            var m = u.isArray,
                g = u.isFunction,
                y = u.toString,
                v = "[object Object]";
            r.prototype = {
                constructor: r,
                logger: b,
                log: x,
                registerHelper: function (e, t) {
                    if (y.call(e) === v) {
                        if (t) throw new f["default"]("Arg not supported with multiple helpers");
                        u.extend(this.helpers, e)
                    } else this.helpers[e] = t
                },
                unregisterHelper: function (e) {
                    delete this.helpers[e]
                },
                registerPartial: function (e, t) {
                    if (y.call(e) === v) u.extend(this.partials, e);
                    else {
                        if ("undefined" == typeof t) throw new f["default"]("Attempting to register a partial as undefined");
                        this.partials[e] = t
                    }
                },
                unregisterPartial: function (e) {
                    delete this.partials[e]
                }
            };
            var b = {
                methodMap: {
                    0: "debug",
                    1: "info",
                    2: "warn",
                    3: "error"
                },
                DEBUG: 0,
                INFO: 1,
                WARN: 2,
                ERROR: 3,
                level: 1,
                log: function (e, t) {
                    if ("undefined" != typeof console && b.level <= e) {
                        var n = b.methodMap[e];
                        (console[n] || console.log).call(console, t)
                    }
                }
            };
            t.logger = b;
            var x = b.log;
            t.log = x
        }, function (e, t, n) {
            "use strict";

            function r(e) {
                this.string = e
            }
            t.__esModule = !0, r.prototype.toString = r.prototype.toHTML = function () {
                return "" + this.string
            }, t["default"] = r, e.exports = t["default"]
        }, function (e, t, n) {
            "use strict";

            function r(e, t) {
                var n = t && t.loc,
                    o = void 0,
                    a = void 0;
                n && (o = n.start.line, a = n.start.column, e += " - " + o + ":" + a);
                for (var s = Error.prototype.constructor.call(this, e), l = 0; l < i.length; l++) this[i[l]] = s[i[l]];
                Error.captureStackTrace && Error.captureStackTrace(this, r), n && (this.lineNumber = o, this.column = a)
            }
            t.__esModule = !0;
            var i = ["description", "fileName", "lineNumber", "message", "name", "number", "stack"];
            r.prototype = new Error, t["default"] = r, e.exports = t["default"]
        }, function (e, t, n) {
            "use strict";

            function r(e) {
                return c[e]
            }

            function i(e) {
                for (var t = 1; t < arguments.length; t++)
                    for (var n in arguments[t]) Object.prototype.hasOwnProperty.call(arguments[t], n) && (e[n] = arguments[t][n]);
                return e
            }

            function o(e, t) {
                for (var n = 0, r = e.length; n < r; n++)
                    if (e[n] === t) return n;
                return -1
            }

            function a(e) {
                if ("string" != typeof e) {
                    if (e && e.toHTML) return e.toHTML();
                    if (null == e) return "";
                    if (!e) return e + "";
                    e = "" + e
                }
                return d.test(e) ? e.replace(f, r) : e
            }

            function s(e) {
                return !e && 0 !== e || !(!m(e) || 0 !== e.length)
            }

            function l(e, t) {
                return e.path = t, e
            }

            function u(e, t) {
                return (e ? e + "." : "") + t
            }
            t.__esModule = !0, t.extend = i, t.indexOf = o, t.escapeExpression = a, t.isEmpty = s, t.blockParams = l, t.appendContextPath = u;
            var c = {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#x27;",
                "`": "&#x60;"
            },
                f = /[&<>"'`]/g,
                d = /[&<>"'`]/,
                p = Object.prototype.toString;
            t.toString = p;
            var h = function (e) {
                return "function" == typeof e
            };
            h(/x/) && (t.isFunction = h = function (e) {
                return "function" == typeof e && "[object Function]" === p.call(e)
            });
            var h;
            t.isFunction = h;
            var m = Array.isArray || function (e) {
                return !(!e || "object" != typeof e) && "[object Array]" === p.call(e)
            };
            t.isArray = m
        }, function (e, t, n) {
            "use strict";

            function r(e) {
                var t = e && e[0] || 1,
                    n = g.COMPILER_REVISION;
                if (t !== n) {
                    if (t < n) {
                        var r = g.REVISION_CHANGES[n],
                            i = g.REVISION_CHANGES[t];
                        throw new m["default"]("Template was precompiled with an older version of Handlebars than the current runtime. Please update your precompiler to a newer version (" + r + ") or downgrade your runtime to an older version (" + i + ").")
                    }
                    throw new m["default"]("Template was precompiled with a newer version of Handlebars than the current runtime. Please update your runtime to a newer version (" + e[1] + ").")
                }
            }

            function i(e, t) {
                function n(n, r, i) {
                    i.hash && (r = p.extend({}, r, i.hash)), n = t.VM.resolvePartial.call(this, n, r, i);
                    var o = t.VM.invokePartial.call(this, n, r, i);
                    if (null == o && t.compile && (i.partials[i.name] = t.compile(n, e.compilerOptions, t), o = i.partials[i.name](r, i)), null != o) {
                        if (i.indent) {
                            for (var a = o.split("\n"), s = 0, l = a.length; s < l && (a[s] || s + 1 !== l); s++) a[s] = i.indent + a[s];
                            o = a.join("\n")
                        }
                        return o
                    }
                    throw new m["default"]("The partial " + i.name + " could not be compiled when running in runtime-only mode")
                }

                function r(t) {
                    var n = void 0 === arguments[1] ? {} : arguments[1],
                        o = n.data;
                    r._setup(n), !n.partial && e.useData && (o = u(t, o));
                    var a = void 0,
                        s = e.useBlockParams ? [] : void 0;
                    return e.useDepths && (a = n.depths ? [t].concat(n.depths) : [t]), e.main.call(i, t, i.helpers, i.partials, o, s, a)
                }
                if (!t) throw new m["default"]("No environment passed to template");
                if (!e || !e.main) throw new m["default"]("Unknown template object: " + typeof e);
                t.VM.checkRevision(e.compiler);
                var i = {
                    strict: function (e, t) {
                        if (!(t in e)) throw new m["default"]('"' + t + '" not defined in ' + e);
                        return e[t]
                    },
                    lookup: function (e, t) {
                        for (var n = e.length, r = 0; r < n; r++)
                            if (e[r] && null != e[r][t]) return e[r][t]
                    },
                    lambda: function (e, t) {
                        return "function" == typeof e ? e.call(t) : e
                    },
                    escapeExpression: p.escapeExpression,
                    invokePartial: n,
                    fn: function (t) {
                        return e[t]
                    },
                    programs: [],
                    program: function (e, t, n, r, i) {
                        var a = this.programs[e],
                            s = this.fn(e);
                        return t || i || r || n ? a = o(this, e, s, t, n, r, i) : a || (a = this.programs[e] = o(this, e, s)), a
                    },
                    data: function (e, t) {
                        for (; e && t--;) e = e._parent;
                        return e
                    },
                    merge: function (e, t) {
                        var n = e || t;
                        return e && t && e !== t && (n = p.extend({}, t, e)), n
                    },
                    noop: t.VM.noop,
                    compilerInfo: e.compiler
                };
                return r.isTop = !0, r._setup = function (n) {
                    n.partial ? (i.helpers = n.helpers, i.partials = n.partials) : (i.helpers = i.merge(n.helpers, t.helpers), e.usePartial && (i.partials = i.merge(n.partials, t.partials)))
                }, r._child = function (t, n, r, a) {
                    if (e.useBlockParams && !r) throw new m["default"]("must pass block params");
                    if (e.useDepths && !a) throw new m["default"]("must pass parent depths");
                    return o(i, t, e[t], n, 0, r, a)
                }, r
            }

            function o(e, t, n, r, i, o, a) {
                function s(t) {
                    var i = void 0 === arguments[1] ? {} : arguments[1];
                    return n.call(e, t, e.helpers, e.partials, i.data || r, o && [i.blockParams].concat(o), a && [t].concat(a))
                }
                return s.program = t, s.depth = a ? a.length : 0, s.blockParams = i || 0, s
            }

            function a(e, t, n) {
                return e ? e.call || n.name || (n.name = e, e = n.partials[e]) : e = n.partials[n.name], e
            }

            function s(e, t, n) {
                if (n.partial = !0, void 0 === e) throw new m["default"]("The partial " + n.name + " could not be found");
                if (e instanceof Function) return e(t, n)
            }

            function l() {
                return ""
            }

            function u(e, t) {
                return t && "root" in t || (t = t ? g.createFrame(t) : {}, t.root = e), t
            }
            var c = n(7)["default"],
                f = n(8)["default"];
            t.__esModule = !0, t.checkRevision = r, t.template = i, t.wrapProgram = o, t.resolvePartial = a, t.invokePartial = s, t.noop = l;
            var d = n(4),
                p = c(d),
                h = n(3),
                m = f(h),
                g = n(1)
        }, function (e, t, n) {
            (function (n) {
                "use strict";
                t.__esModule = !0, t["default"] = function (e) {
                    var t = "undefined" != typeof n ? n : window,
                        r = t.Handlebars;
                    e.noConflict = function () {
                        t.Handlebars === e && (t.Handlebars = r)
                    }
                }, e.exports = t["default"]
            }).call(t, function () {
                return this
            }())
        }, function (e, t, n) {
            "use strict";
            t["default"] = function (e) {
                if (e && e.__esModule) return e;
                var t = {};
                if ("object" == typeof e && null !== e)
                    for (var n in e) Object.prototype.hasOwnProperty.call(e, n) && (t[n] = e[n]);
                return t["default"] = e, t
            }, t.__esModule = !0
        }, function (e, t, n) {
            "use strict";
            t["default"] = function (e) {
                return e && e.__esModule ? e : {
                    "default": e
                }
            }, t.__esModule = !0
        }])
    }),
    function (e) {
        "function" == typeof define && define.amd ? define(["jquery"], e) : e("object" == typeof exports ? require("jquery") : jQuery)
    }(function (e) {
        function t(e) {
            return s.raw ? e : encodeURIComponent(e)
        }

        function n(e) {
            return s.raw ? e : decodeURIComponent(e)
        }

        function r(e) {
            return t(s.json ? JSON.stringify(e) : String(e))
        }

        function i(e) {
            0 === e.indexOf('"') && (e = e.slice(1, -1).replace(/\\"/g, '"').replace(/\\\\/g, "\\"));
            try {
                return e = decodeURIComponent(e.replace(a, " ")), s.json ? JSON.parse(e) : e
            } catch (t) { }
        }

        function o(t, n) {
            var r = s.raw ? t : i(t);
            return e.isFunction(n) ? n(r) : r
        }
        var a = /\+/g,
            s = e.cookie = function (i, a, l) {
                if (void 0 !== a && !e.isFunction(a)) {
                    if (l = e.extend({}, s.defaults, l), "number" == typeof l.expires) {
                        var u = l.expires,
                            c = l.expires = new Date;
                        c.setTime(+c + 864e5 * u)
                    }
                    return document.cookie = [t(i), "=", r(a), l.expires ? "; expires=" + l.expires.toUTCString() : "", l.path ? "; path=" + l.path : "", l.domain ? "; domain=" + l.domain : "", l.secure ? "; secure" : ""].join("")
                }
                for (var f = i ? void 0 : {}, d = document.cookie ? document.cookie.split("; ") : [], p = 0, h = d.length; p < h; p++) {
                    var m = d[p].split("="),
                        g = n(m.shift()),
                        y = m.join("=");
                    if (i && i === g) {
                        f = o(y, a);
                        break
                    }
                    i || void 0 === (y = o(y)) || (f[g] = y)
                }
                return f
            };
        s.defaults = {}, e.removeCookie = function (t, n) {
            return void 0 !== e.cookie(t) && (e.cookie(t, "", e.extend({}, n, {
                expires: -1
            })), !e.cookie(t))
        }
    }),
    function (e, t) {
        "function" == typeof define && define.amd ? define(["jquery"], t) : (window.XC_VM = window.XC_VM || {}, window.XC_VM[e] = t())
    }("utilities", function () {
        var e = !1,
            t = "JSON",
            n = function () {
                var e, t;
                return "undefined" != typeof window.innerWidth ? (e = window.innerWidth, t = window.innerHeight) : "undefined" != typeof document.documentElement && "undefined" != typeof document.documentElement.clientWidth && 0 != document.documentElement.clientWidth ? (e = document.documentElement.clientWidth, t = document.documentElement.clientHeight) : (e = document.getElementsByTagName("body")[0].clientWidth, t = document.getElementsByTagName("body")[0].clientHeight), {
                    width: e,
                    height: t
                }
            },
            r = function (e) {
                var t = e || moment().startOf("hour"), n = new Date(t.year(), t.month(), t.date(), t.hour(), t.minute());
                return t
            },
            i = function () {
                var e = navigator.userAgent.indexOf(".NET CLR") > -1,
                    t = e || navigator.appVersion.indexOf("MSIE") != -1;
                return t
            },
            o = function () {
                for (var e, t = 3, n = document.createElement("div"), r = n.getElementsByTagName("i"); n.innerHTML = "<!--[if gt IE " + ++t + "]><i></i><![endif]-->", r[0];);
                return t > 4 ? t : e
            }(),
            a = function () {
                return /iPhone|Android|BlackBerry/.test(navigator.userAgent)
            },
            s = function () {
                return /iPhone|iPod|iPad|Android|BlackBerry/.test(navigator.userAgent)
            },
            l = function () {
                return /iPhone|iPod|iPad/.test(navigator.userAgent)
            },
            u = function () {
                return /Android/.test(navigator.userAgent)
            },
            c = function (e, t, n) {
                return "undefined" == typeof e || null == e ? e : e.length > t ? e.substring(0, t) + (n ? "..." : "") : e
            },
            f = function (e, t) {
                for (var n = e.split(" "), r = !1, i = 0, o = 0; o < n.length; o++) {
                    if (i++, i + n[o].length > t) {
                        r = !0;
                        break
                    }
                    for (var a = 0; a < n[o].length; a++) {
                        r = !0;
                        break
                    }
                    i += n[o].length
                }
                return r ? e.substr(0, i - 1).replace(/^\s+|\s+$/g, "") + "..." : e
            },
            d = function (e, t) {
                for (var n = e.split(" "), r = [], i = 0, o = !1, a = 0; a < n.length; a++)
                    if (i++, r.push(n[a]), i >= t) {
                        o = !0;
                        break
                    } var s = r.join(" ");
                return o && (s += "..."), s
            },
            p = function (e, t) {
                if ("undefined" != typeof e && "undefined" != typeof t) {
                    for (var n = [], r = e.className.split(" "), i = 0; i < r.length; i++) r[i] !== t && n.push(r[i]);
                    e.className = n.join(" ")
                }
            },
            h = function (e, t) {
                if ("undefined" != typeof e && "undefined" != typeof t) {
                    for (var n = e.className.split(" "), r = 0; r < n.length; r++)
                        if (n[r] === t) return;
                    e.className += " " + t
                }
            },
            m = [function () {
                return new XMLHttpRequest
            }, function () {
                return new ActiveXObject("Msxml2.XMLHTTP")
            }, function () {
                return new ActiveXObject("Msxml3.XMLHTTP")
            }, function () {
                return new ActiveXObject("Microsoft.XMLHTTP")
            }],
            g = function () {
                for (var e = !1, t = 0; t < m.length; t++) {
                    try {
                        e = m[t]()
                    } catch (n) {
                        continue
                    }
                    break
                }
                return e
            },
            y = function (e, t, n, r, i, o) {
                var o = "undefined" == typeof o || o,
                    a = "undefined" != typeof n ? n : "GET",
                    s = g(),
                    t = b(t),
                    e = e + "?" + t;
                s.onreadystatechange = function () {
                    if (s.readyState === XMLHttpRequest.DONE) {
                        var e;
                        return s.responseText.length && (e = x(s.responseText)), 200 === s.status || 304 === s.status ? void r(e) : void i(e)
                    }
                }, s.open(a, e, o), "post" === n.toLowerCase() && s.setRequestHeader("Content-type", "application/x-www-form-urlencoded"), s.send(t)
            },
            v = function (e, t, n) {
                t.addEventListener ? t.addEventListener(e, n, !1) : t.attachEvent ? t.attachEvent("on" + e, n) : t[e] = n
            },
            b = function (e) {
                var t = [];
                for (k in e) e.hasOwnProperty(k) && t.push([k, encodeURIComponent(e[k])].join("="));
                return t.join("&")
            },
            x = function (e, n) {
                var n = "undefined" != typeof n ? n : t;
                switch (n) {
                    case t:
                        return JSON.parse(e);
                    default:
                        return e
                }
            };
        var w = function (t, n) {
            e && window.console && console.log(t, n)
        },
            T = function () {
                e = !e
            },
            N = function (e, t, n, r, i, o) {
                t = escape(t);
                var a = e + "=" + t + (n ? "; expires=" + n.toGMTString() : "") + (r ? "; path=" + r : "") + (i ? "; domain=" + i : "") + (o ? "; secure" : "");
                document.cookie = a
            },
            C = function (e) {
                var t = e + "=";
                if (document.cookie.length > 0) {
                    var n = document.cookie.indexOf(t);
                    if (n !== -1) {
                        n += t.length;
                        var r = document.cookie.indexOf(";", n);
                        return r === -1 && (r = document.cookie.length), unescape(document.cookie.substring(n, r))
                    }
                }
                return !1
            },
            E = function (e, t, n) {
                C(e) && (document.cookie = e + "=" + (t ? ";path=" + t : "") + (n ? ";domain=" + n : "") + ";expires=Thu, 01-Jan-70 00:00:01 GMT")
            },
            S = function () {
                var e;
                try {
                    e = Boolean(new ActiveXObject("ShockwaveFlash.ShockwaveFlash"))
                } catch (t) {
                    e = "undefined" != typeof navigator.mimeTypes["application/x-shockwave-flash"]
                }
                return e
            },
            A = function (e) {
                !!e != !1 && (e.style.display = "block" === e.style.display ? "none" : "block")
            };
        return {
            viewPort: function () {
                return n()
            },
            getBritishTime: function (e) {
                return r(e)
            },
            IEVersionNumber: function () {
                return o
            }(),
            isIE: function () {
                return i()
            },
            isMobile: function () {
                return a()
            },
            isTouch: function () {
                return s()
            },
            isIOS: function () {
                return l()
            },
            isAndroid: function () {
                return u()
            },
            truncateText: function (e, t, n) {
                return c(e, t, n)
            },
            truncateStringToWordByCharAmount: function (e, t) {
                return f(e, t)
            },
            truncateStringByWordAmount: function (e, t) {
                return d(e, t)
            },
            addEvent: function (e, t, n) {
                return v(e, t, n)
            },
            xhr: function (e, t, n, r, i, o) {
                y(e, t, n, r, i, o)
            },
            addClass: function (e, t) {
                h(e, t)
            },
            removeClass: function (e, t) {
                p(e, t)
            },
            logCatch: function (e, t) {
                w(e, t)
            },
            toggleDebug: function () {
                T()
            },
            getCookie: function (e) {
                return C(e)
            },
            setCookie: function (e, t, n, r, i, o) {
                N(e, t, n, r, i, o)
            },
            delCookie: function (e, t, n) {
                E(e, t, n)
            },
            isFlashEnabled: function () {
                return S()
            },
            toggleVisibility: function (e) {
                A(e)
            }
        }
    }),
    function (e, t) {
        "function" == typeof define && define.amd ? define(["jquery"], t) : (window.XC_VM = window.XC_VM || {}, window.XC_VM[e] = t())
    }("EnvConfigHelper", function () {
        var e = function () {
            return ""
        },
            t = function () {
                return ""
            },
            n = function () {
                return window.XC_VM && window.XC_VM.Listings && window.XC_VM.Listings.ListingsGridV2Enabled
            };
        return {
            getServiceProviderUrl: function () {
                return e()
            },
            getServiceProviderUrlV2: function () {
                return t()
            },
            listingsGridV2Enabled: function () {
                return n()
            }
        }
    }),
    function (e, t) {
        "function" == typeof define && define.amd ? define(e, ["utilities"], t) : (window.XC_VM = window.XC_VM || {}, window.XC_VM[e] = t())
    }("PubSub", function () {
        var e = {},
            t = "undefined" != typeof this.hasOwnProperty ? this.hasOwnProperty : Object.prototype.hasOwnProperty;
        return {
            subscribe: function (n, r) {
                t.call(e, n) || (e[n] = []);
                var i = e[n].push(r) - 1;
                return {
                    remove: function () {
                        delete e[n][i]
                    }
                }
            },
            publish: function (n, r) {
                t.call(e, n) && e[n].forEach(function (e) {
                    e(void 0 != r ? r : {})
                })
            }
        }
    }), window.XC_VM = window.XC_VM || {}, window.XC_VM.AmazonDirectMatchBuy = function () {
        var e = "3331",
            t = !1,
            n = function () {
                window.console && t && console.log("AmazonDirectMatchBuy: init"), window.googletag = window.googletag || {}, window.googletag.cmd = window.googletag.cmd || [];
                try {
                    window.console && t && console.log("AmazonDirectMatchBuy: Assign callback method"), amznads.getAdsCallback(e, function () {
                        window.console && t && console.log("AmazonDirectMatchBuy: getAdsCallback: callback triggered"), amznads.setTargetingForGPTAsync("amznslots")
                    })
                } catch (n) {
                    window.console && t && console.log("AmazonDirectMatchBuy: Error: ", n)
                }
            };
        return {
            init: function () {
                n()
            }
        }
    }(),
    function (e, t) {
        "function" == typeof define && define.amd ? define(e, [], t) : (window.XC_VM = window.XC_VM || {}, window.XC_VM[e] = t())
    }("UriService", function () {
        var e = function () {
            return {
                templateUri: "/{specialization}-programme/e/{id}/{brandTitle}{slugSeparator}{episodeNumbers}{episodeTitle}/",
                requiredComponents: ["id", "specialization"],
                optionalComponents: ["brandTitle", "episodeTitle", "episodeNumbers"],
                systemComponents: {
                    slugSeparator: "--"
                }
            }
        },
            t = function (e) {
                var t = e;
                return t = t.toLowerCase(), t = t.replace(/[^a-z0-9\s-]/gi, ""), t = t.replace(/\s/g, "-")
            },
            n = function (e, n) {
                var r, i, o = JSON.parse(JSON.stringify(n));
                for (r = 0; r < e.requiredComponents.length; r++) i = e.requiredComponents[r], o[i] && (o[i] = t(o[i]));
                for (i = null, r = 0; r < e.optionalComponents.length; r++) i = e.optionalComponents[r], o[i] && (o[i] = t(o[i]));
                return o
            },
            r = function (e, t, n) {
                for (var r = 0; r < e.requiredComponents.length; r++) {
                    var i = e.requiredComponents[r];
                    if ("undefined" == typeof n[i]) return !1;
                    t = t.replace(o.start + i + o.end, n[i])
                }
                return t
            },
            i = function (e, t, n) {
                var r = "undefined" != typeof n.brandTitle && "undefined" != typeof n.episodeTitle;
                t = t.replace(o.start + "slugSeparator" + o.end, r ? e.systemComponents.slugSeparator : "");
                for (var i = 0; i < e.optionalComponents.length; i++) {
                    var a = e.optionalComponents[i];
                    t = t.replace(o.start + a + o.end, n[a] ? n[a] : "")
                }
                return t
            },
            o = {
                start: "{",
                end: "}"
            },
            a = {
                episode: {
                    getTemplateSpec: e,
                    replaceRequiredTokens: r,
                    replaceOptionalTokens: i,
                    sanitise: n
                }
            },
            s = function (e) {
                if (!a[e.type]) return !1;
                var t = a[e.type],
                    n = t.getTemplateSpec(),
                    r = t.sanitise(n, e),
                    i = n.templateUri;
                return i = t.replaceRequiredTokens(n, i, r), i = t.replaceOptionalTokens(n, i, r)
            };
        return {
            buildUri: s
        }
    });

window.XC_VM = window.XC_VM || {}, window.XC_VM.Affixer = function () {
    var e, n, t, a, i, s, r, o, l, u, c, d = function () {
        return Math.max(document.body.scrollTop, document.documentElement.scrollTop) + a
    },
        h = function () {
            for (var e = 0, n = r; n;) e += n.offsetTop, n = n.offsetParent;
            return e
        },
        f = function () {
            return parseInt(l.offsetHeight - o.offsetHeight - i, 10)
        },
        p = function () {
            return {
                offsetTop: a,
                offsetBottom: i,
                fixTo: s
            }
        },
        m = function () {
            try {
                if (e.disableForTouchDevices && n.isTouch()) return;
                var t = p();
                if (d() > h()) {
                    if (d() > h() + f()) return o.style.position = u, o.style.top = f() + "px", r.style.height = c, void n.removeClass("js-fixed");
                    var a = "top" === t.fixTo.toLowerCase() ? t.offsetTop : t.offsetBottom;
                    o.style[t.fixTo] = a + "px", o.style.position = "fixed", n.addClass(o, "js-fixed"), r.style.height = o.offsetHeight + "px"
                } else o.style.position = u, o.style[t.fixTo] = "0", r.style.height = c, n.removeClass(o, "js-fixed")
            } catch (i) { }
        },
        g = function () {
            m()
        },
        v = function () {
            m()
        },
        y = function () {
            n.addEvent("scroll", window, g), n.addEvent("resize", window, v)
        },
        w = function () { },
        T = function (e) {
            e = "undefined" != typeof e && e, e && S(), y(), g()
        },
        b = function () {
            t.subscribe("PROGRAMME_NAVIGATION:GONE_TO_EPISODE_PAGE_COMPLETE", T), t.subscribe("PROGRAMME_NAVIGATION:GONE_TO_FILM_PAGE_COMPLETE", T), t.subscribe("EPISODE:CAST_BUTTON_CLICKED", v)
        },
        _ = function () {
            u = o.style.position
        },
        D = function () {
            c = r.style.height
        },
        k = function () {
            return "undefined" != typeof e.offsetTop && "undefined" != typeof e.mobile && "undefined" != typeof e.mobile.offsetTop ? window.innerWidth < 768 ? e.mobile.offsetTop : e.offsetTop : "undefined" != typeof e.offsetTop ? e.offsetTop : 0
        },
        S = function () {
            "undefined" != typeof e && (s = "top", a = k(), r = "undefined" != typeof e.container ? document.querySelector(e.container) : null, o = "undefined" != typeof e.element ? document.querySelector(e.element) : null, l = "undefined" != typeof e.element ? document.querySelector(e.confinement) : null, i = "undefined" != typeof e.offsetBottom ? e.offsetBottom : null)
        };
    this.init = function (a, i, s) {
        e = a, n = i, t = s, S(), _(), D(), T(!1), b()
    }, this.reassign = function () {
        T(!0)
    }, this.destroy = function () {
        w()
    }
}, this.Handlebars = this.Handlebars || {}, this.Handlebars.templates = this.Handlebars.templates || {}, this.Handlebars.templates.jumpTimes = Handlebars.template({
    1: function (e, n, t, a) {
        var i, s, r = n.helperMissing,
            o = "function",
            l = this.escapeExpression;
        return '    <li class="gtm-time-jump-mobile listings-jump-item' + (null != (i = n["if"].call(e, null != e ? e.highlight : e, {
            name: "if",
            hash: {},
            fn: this.program(2, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + ' js-jump-item" data-hour="' + l((s = null != (s = n.hour || (null != e ? e.hour : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "hour",
            hash: {},
            data: a
        }) : s)) + '">' + l((s = null != (s = n.displayTime || (null != e ? e.displayTime : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "displayTime",
            hash: {},
            data: a
        }) : s)) + "</li>\n"
    },
    2: function (e, n, t, a) {
        return " bold"
    },
    compiler: [6, ">= 2.0.0-beta.1"],
    main: function (e, n, t, a) {
        var i;
        return '<ul>\n    <li class="listings-jump-item bold js-jump-item js-now-btn">NOW</li>\n' + (null != (i = n.each.call(e, null != e ? e.times : e, {
            name: "each",
            hash: {},
            fn: this.program(1, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "</ul>\n"
    },
    useData: !0
}), this.Handlebars.templates.listingsDayNav = Handlebars.template({
    1: function (e, n, t, a) {
        var i, s = n.helperMissing,
            r = "function",
            o = this.escapeExpression;
        return '        <li class="js-day-nav-item js-day-' + o((i = null != (i = n.fullDate || (null != e ? e.fullDate : e)) ? i : s, typeof i === r ? i.call(e, {
            name: "fullDate",
            hash: {},
            data: a
        }) : i)) + " " + o((i = null != (i = n.activeClass || (null != e ? e.activeClass : e)) ? i : s, typeof i === r ? i.call(e, {
            name: "activeClass",
            hash: {},
            data: a
        }) : i)) + '"><a href="#" class="js-day-nav-link" data-diff="' + o((i = null != (i = n.diff || (null != e ? e.diff : e)) ? i : s, typeof i === r ? i.call(e, {
            name: "diff",
            hash: {},
            data: a
        }) : i)) + '">' + o((i = null != (i = n.displayName || (null != e ? e.displayName : e)) ? i : s, typeof i === r ? i.call(e, {
            name: "displayName",
            hash: {},
            data: a
        }) : i)) + "</a></li>\n"
    },
    compiler: [6, ">= 2.0.0-beta.1"],
    main: function (e, n, t, a) {
        var i;
        return '<div class="js-listings-day-nav-inner">\n    <ul class="cf">\n' + (null != (i = n.each.call(e, null != e ? e.days : e, {
            name: "each",
            hash: {},
            fn: this.program(1, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "    </ul>\n</div>"
    },
    useData: !0
}), this.Handlebars.templates.listingsGrid = Handlebars.template({
    1: function (e, n, t, a) {
        var i, s, r = n.helperMissing,
            o = "function",
            l = this.escapeExpression;
        return '    <div class="channel-row" data-channel-id="' + l((s = null != (s = n.Id || (null != e ? e.Id : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "Id",
            hash: {},
            data: a
        }) : s)) + '" data-channel-name="' + l((s = null != (s = n.DisplayName || (null != e ? e.DisplayName : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "DisplayName",
            hash: {},
            data: a
        }) : s)) + '">\n\n        <div class="listings-channel" data-channel-id="' + l((s = null != (s = n.Id || (null != e ? e.Id : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "Id",
            hash: {},
            data: a
        }) : s)) + '">\n            <div onClick="setChannel(' + l((s = null != (s = n.Id || (null != e ? e.Id : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "Id",
            hash: {},
            data: a
        }) : s)) + ');" class="listings-channel-summary js-listings-channel-summary">\n                <div class="channel-img-wrapper">\n                    <img loading="lazy" class="channel-img" src="resize?icon=1&url=' + encodeURIComponent(l((s = null != (s = n.Image || (null != e ? e.Image : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "Image",
            hash: {},
            data: a
        }) : s))) + '" />\n                <h2 class="channel-title-hidden"><strong>' + l((s = null != (s = n.DisplayName || (null != e ? e.DisplayName : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "DisplayName",
            hash: {},
            data: a
        }) : s)) + '</strong><br/><span style="font-size:11px;">' + l((s = null != (s = n.CategoryName || (null != e ? e.CategoryName : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "CategoryName",
            hash: {},
            data: a
        }) : s)) + '</span></h2>\n\n                </div>\n\n                </ul>\n\n            </div>\n        </div>\n\n        <ul class="programme-list" data-channel-id="' + l((s = null != (s = n.Id || (null != e ? e.Id : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "Id",
            hash: {},
            data: a
        }) : s)) + '">\n' + (null != (i = n.each.call(e, null != e ? e.TvListings : e, {
            name: "each",
            hash: {},
            fn: this.program(19, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "        </ul>\n    </div>\n"
    },
    19: function (e, n, t, a) {
        var i, s, r = n.helperMissing,
            o = "function",
            l = this.escapeExpression;
        return '            <li class="programme cf" style="width:' + l((s = null != (s = n.RelativeSize || (null != e ? e.RelativeSize : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "RelativeSizePerc",
            hash: {},
            data: a
        }) : s)) + '%">\n\n' + (null != (i = n["if"].call(e, null != e ? e.showContent : e, {
            name: "if",
            hash: {},
            fn: this.program(26, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "\n" + (null != (i = n.unless.call(e, null != e ? e.showContent : e, {
            name: "unless",
            hash: {},
            fn: this.program(45, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "\n            </li>\n"
    },
    26: function (e, n, t, a) {
        var i, s, c;
        if (e.Start > moment().unix()) {
            c = "";
        } else if (e.Archive) {
            c = "setChannel(" + e.ChannelId + ", " + e.Archive[0] + ", " + e.Archive[1] + ");";
        } else {
            c = "setChannel(" + e.ChannelId + ");";
        }
        if (e.isTiny) {
            t = ' tooltip-top" title="' + e.StartTime + " - " + e.Title.replace('"', '\"');
        } else {
            t = '';
        }
        return '                <a href="javascript:void(0);" onClick="' + c + '" class="programme-wrapper' + t + '">\n                    <div class="programme-inner">\n\n                        <h2 class="programme-title">\n' + (null != (i = n.unless.call(e, null != e ? e.offAir : e, {
            name: "unless",
            hash: {},
            fn: this.program(35, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + '\n                        </h2>\n                        <span class="programme-info">\n                            <span class="programme-time">\n                                ' + (null != (i = n.unless.call(e, null != e ? e.offAir : e, {
            name: "unless",
            hash: {},
            fn: this.program(39, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "                        </span>\n                    </div>\n                </a>\n"
    },
    27: function (e, n, t, a) {
        return " show-overlay"
    },
    35: function (e, n, t, a) {
        var i, s;
        if (e.Archive && e.End <= moment().unix()) {
            c = "<i class='icon ion-md-radio-button-on icon-archive'></i>";
        } else {
            c = "";
        }
        return '<span class="title-inner">' + c + (null != (s = null != (s = n.Title || (null != e ? e.Title : e)) ? s : n.helperMissing, i = "function" == typeof s ? s.call(e, {
            name: "Title",
            hash: {},
            data: a
        }) : s) ? i : "") + "</span>"
    },
    39: function (e, n, t, a) {
        var i;
        return this.escapeExpression((i = null != (i = n.StartTime || (null != e ? e.StartTime : e)) ? i : n.helperMissing, "function" == typeof i ? i.call(e, {
            name: "StartTime",
            hash: {},
            data: a
        }) : i))
    },
    41: function (e, n, t, a) {
        var i;
        return "Returns at " + this.escapeExpression((i = null != (i = n.EndTime || (null != e ? e.EndTime : e)) ? i : n.helperMissing, "function" == typeof i ? i.call(e, {
            name: "EndTime",
            hash: {},
            data: a
        }) : i))
    },
    45: function (e, n, t, a) {
        var i;
        return '                <div class="programme-wrapper title-only' + (null != (i = n.unless.call(e, null != e ? e.offAir : e, {
            name: "unless",
            hash: {},
            fn: this.program(27, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "\">\n                </div>\n"
    },
    compiler: [6, ">= 2.0.0-beta.1"],
    main: function (e, n, t, a) {
        var i;
        return '<div class="listings-pane" data-start-time="' + this.escapeExpression(this.lambda(null != (i = null != e ? e.Legend : e) ? i.CurrentTimePeriodIdentifier : i, e)) + '">\n' + (null != (i = n.each.call(e, null != e ? e.Channels : e, {
            name: "each",
            hash: {},
            fn: this.program(1, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "</div>\n"
    },
    usePartial: !0,
    useData: !0
}), this.Handlebars.templates.listingsTimeBar = Handlebars.template({
    1: function (e, n, t, a) {
        var i, s = n.helperMissing,
            r = "function",
            o = this.escapeExpression;
        return '    <li style="width:' + o((i = null != (i = n.width || (null != e ? e.width : e)) ? i : s, typeof i === r ? i.call(e, {
            name: "width",
            hash: {},
            data: a
        }) : i)) + '%;"><span class="listings-time ' + o((i = null != (i = n.className || (null != e ? e.className : e)) ? i : s, typeof i === r ? i.call(e, {
            name: "className",
            hash: {},
            data: a
        }) : i)) + '" data-hour="' + o((i = null != (i = n.hour || (null != e ? e.hour : e)) ? i : s, typeof i === r ? i.call(e, {
            name: "hour",
            hash: {},
            data: a
        }) : i)) + '">' + o((i = null != (i = n.time || (null != e ? e.time : e)) ? i : s, typeof i === r ? i.call(e, {
            name: "time",
            hash: {},
            data: a
        }) : i)) + "</span></li>\n"
    },
    compiler: [6, ">= 2.0.0-beta.1"],
    main: function (e, n, t, a) {
        var i;
        return '<ul class="listings-times cf">\n' + (null != (i = n.each.call(e, null != e ? e.times : e, {
            name: "each",
            hash: {},
            fn: this.program(1, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + "</ul>"
    },
    useData: !0
}), this.Handlebars.templates.timeSelector = Handlebars.template({
    1: function (e, n, t, a) {
        var i, s, r = n.helperMissing,
            o = "function",
            l = this.escapeExpression;
        return '            <li class="gtm-time-jump-desktop time-item js-time-item js-time-item-' + l((s = null != (s = n.trueHour || (null != e ? e.trueHour : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "trueHour",
            hash: {},
            data: a
        }) : s)) + (null != (i = n["if"].call(e, null != e ? e.state : e, {
            name: "if",
            hash: {},
            fn: this.program(2, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + (null != (i = n["if"].call(e, null != e ? e.midday : e, {
            name: "if",
            hash: {},
            fn: this.program(4, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + (null != (i = n["if"].call(e, null != e ? e.showMeridiem : e, {
            name: "if",
            hash: {},
            fn: this.program(6, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + '" data-hour="' + l((s = null != (s = n.trueHour || (null != e ? e.trueHour : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "trueHour",
            hash: {},
            data: a
        }) : s)) + '" title="Jump to ' + l((s = null != (s = n.displayHour || (null != e ? e.displayHour : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "displayHour",
            hash: {},
            data: a
        }) : s)) + l((s = null != (s = n.meridiem || (null != e ? e.meridiem : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "meridiem",
            hash: {},
            data: a
        }) : s)) + '">\n                <span class="hour">' + l((s = null != (s = n.displayHour || (null != e ? e.displayHour : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "displayHour",
            hash: {},
            data: a
        }) : s)) + '</span>\n                <span class="js-meridiem meridiem">' + l((s = null != (s = n.meridiem || (null != e ? e.meridiem : e)) ? s : r, typeof s === o ? s.call(e, {
            name: "meridiem",
            hash: {},
            data: a
        }) : s)) + "</span>\n            </li>\n"
    },
    2: function (e, n, t, a) {
        var i;
        return " " + this.escapeExpression((i = null != (i = n.state || (null != e ? e.state : e)) ? i : n.helperMissing, "function" == typeof i ? i.call(e, {
            name: "state",
            hash: {},
            data: a
        }) : i))
    },
    4: function (e, n, t, a) {
        return " midday"
    },
    6: function (e, n, t, a) {
        return " show-meridiem"
    },
    compiler: [6, ">= 2.0.0-beta.1"],
    main: function (e, n, t, a) {
        var i;
        return '<div class="time-selector-clock">\n    <span>jump</span>\n    <span class="isvg isvg-clock"></span>\n</div>\n\n<div class="time-selector-nav js-time-selector-nav" data-dir="-">\n    <span class="isvg isvg-left-dir"></span>\n</div>\n\n<div class="time-selector-container">\n    <div class="time-selector-scroller js-time-selector-scroller">\n        <ul class="time-selector-list cf">\n' + (null != (i = n.each.call(e, null != e ? e.times : e, {
            name: "each",
            hash: {},
            fn: this.program(1, a, 0),
            inverse: this.noop,
            data: a
        })) ? i : "") + '        </ul>\n    </div>\n</div>\n\n<div class="time-selector-nav js-time-selector-nav" data-dir="+">\n    <span class="isvg isvg-right-dir"></span>\n</div>\n'
    },
    useData: !0
}),
    function (e) {
        "function" == typeof define && define.amd && define.amd.jQuery ? define(["jquery"], e) : e(jQuery)
    }(function (e) {
        function n(n) {
            return !n || void 0 !== n.allowPageScroll || void 0 === n.swipe && void 0 === n.swipeStatus || (n.allowPageScroll = u), void 0 !== n.click && void 0 === n.tap && (n.tap = n.click), n || (n = {}), n = e.extend({}, e.fn.swipe.defaults, n), this.each(function () {
                var a = e(this),
                    i = a.data(M);
                i || (i = new t(this, n), a.data(M, i))
            })
        }

        function t(n, t) {
            function R(n) {
                if (!(ue() || e(n.target).closest(t.excludedElements, Fe).length > 0)) {
                    var a, i = n.originalEvent ? n.originalEvent : n,
                        s = k ? i.touches[0] : i;
                    return ze = T, k ? qe = i.touches.length : n.preventDefault(), Oe = 0, Pe = null, We = null, Ne = 0, He = 0, Be = 0, Ue = 1, Ae = 0, Ve = pe(), Ge = ve(), oe(), !k || qe === t.fingers || t.fingers === y || G() ? (de(0, s), Ze = Ce(), 2 == qe && (de(1, i.touches[1]), He = Be = Te(Ve[0].start, Ve[1].start)), (t.swipeStatus || t.pinchStatus) && (a = O(i, ze))) : a = !1, a === !1 ? (ze = D, O(i, ze), a) : (t.hold && (en = setTimeout(e.proxy(function () {
                        Fe.trigger("hold", [i.target]), t.hold && (a = t.hold.call(Fe, i, i.target))
                    }, this), t.longTapThreshold)), ce(!0), null)
                }
            }

            function I(e) {
                var n = e.originalEvent ? e.originalEvent : e;
                if (ze !== _ && ze !== D && !le()) {
                    var a, i = k ? n.touches[0] : n,
                        s = he(i);
                    if (Qe = Ce(), k && (qe = n.touches.length), t.hold && clearTimeout(en), ze = b, 2 == qe && (0 == He ? (de(1, n.touches[1]), He = Be = Te(Ve[0].start, Ve[1].start)) : (he(n.touches[1]), Be = Te(Ve[0].end, Ve[1].end), We = _e(Ve[0].end, Ve[1].end)), Ue = be(He, Be), Ae = Math.abs(He - Be)), qe === t.fingers || t.fingers === y || !k || G()) {
                        if (Pe = Se(s.start, s.end), A(e, Pe), Oe = De(s.start, s.end), Ne = we(), me(Pe, Oe), (t.swipeStatus || t.pinchStatus) && (a = O(n, ze)), !t.triggerOnTouchEnd || t.triggerOnTouchLeave) {
                            var r = !0;
                            if (t.triggerOnTouchLeave) {
                                var o = Me(this);
                                r = Re(s.end, o)
                            } !t.triggerOnTouchEnd && r ? ze = Y(b) : t.triggerOnTouchLeave && !r && (ze = Y(_)), ze != D && ze != _ || O(n, ze)
                        }
                    } else ze = D, O(n, ze);
                    a === !1 && (ze = D, O(n, ze))
                }
            }

            function L(e) {
                var n = e.originalEvent;
                return k && n.touches.length > 0 ? (re(), !0) : (le() && (qe = $e), Qe = Ce(), Ne = we(), H() || !N() ? (ze = D, O(n, ze)) : t.triggerOnTouchEnd || 0 == t.triggerOnTouchEnd && ze === b ? (e.preventDefault(), ze = _, O(n, ze)) : !t.triggerOnTouchEnd && J() ? (ze = _, P(n, ze, f)) : ze === b && (ze = D, O(n, ze)), ce(!1), null)
            }

            function x() {
                qe = 0, Qe = 0, Ze = 0, He = 0, Be = 0, Ue = 1, oe(), ce(!1)
            }

            function j(e) {
                var n = e.originalEvent;
                t.triggerOnTouchLeave && (ze = Y(_), O(n, ze))
            }

            function E() {
                Fe.unbind(Le, R), Fe.unbind(Ye, x), Fe.unbind(xe, I), Fe.unbind(je, L), Ee && Fe.unbind(Ee, j), ce(!1)
            }

            function Y(e) {
                var n = e,
                    a = U(),
                    i = N(),
                    s = H();
                return !a || s ? n = D : !i || e != b || t.triggerOnTouchEnd && !t.triggerOnTouchLeave ? !i && e == _ && t.triggerOnTouchLeave && (n = D) : n = _, n
            }

            function O(e, n) {
                var t = void 0;
                return V() || q() || F() || G() ? ((V() || q()) && (t = P(e, n, d)), (F() || G()) && t !== !1 && (t = P(e, n, h))) : ie() && t !== !1 ? t = P(e, n, p) : se() && t !== !1 ? t = P(e, n, m) : ae() && t !== !1 && (t = P(e, n, f)), n === D && x(e), n === _ && (k ? 0 == e.touches.length && x(e) : x(e)), t
            }

            function P(n, u, c) {
                var g = void 0;
                if (c == d) {
                    if (Fe.trigger("swipeStatus", [u, Pe || null, Oe || 0, Ne || 0, qe, Ve]), t.swipeStatus && (g = t.swipeStatus.call(Fe, n, u, Pe || null, Oe || 0, Ne || 0, qe, Ve), g === !1)) return !1;
                    if (u == _ && z()) {
                        if (Fe.trigger("swipe", [Pe, Oe, Ne, qe, Ve]), t.swipe && (g = t.swipe.call(Fe, n, Pe, Oe, Ne, qe, Ve), g === !1)) return !1;
                        switch (Pe) {
                            case a:
                                Fe.trigger("swipeLeft", [Pe, Oe, Ne, qe, Ve]), t.swipeLeft && (g = t.swipeLeft.call(Fe, n, Pe, Oe, Ne, qe, Ve));
                                break;
                            case i:
                                Fe.trigger("swipeRight", [Pe, Oe, Ne, qe, Ve]), t.swipeRight && (g = t.swipeRight.call(Fe, n, Pe, Oe, Ne, qe, Ve));
                                break;
                            case s:
                                Fe.trigger("swipeUp", [Pe, Oe, Ne, qe, Ve]), t.swipeUp && (g = t.swipeUp.call(Fe, n, Pe, Oe, Ne, qe, Ve));
                                break;
                            case r:
                                Fe.trigger("swipeDown", [Pe, Oe, Ne, qe, Ve]), t.swipeDown && (g = t.swipeDown.call(Fe, n, Pe, Oe, Ne, qe, Ve))
                        }
                    }
                }
                if (c == h) {
                    if (Fe.trigger("pinchStatus", [u, We || null, Ae || 0, Ne || 0, qe, Ue, Ve]), t.pinchStatus && (g = t.pinchStatus.call(Fe, n, u, We || null, Ae || 0, Ne || 0, qe, Ue, Ve), g === !1)) return !1;
                    if (u == _ && W()) switch (We) {
                        case o:
                            Fe.trigger("pinchIn", [We || null, Ae || 0, Ne || 0, qe, Ue, Ve]), t.pinchIn && (g = t.pinchIn.call(Fe, n, We || null, Ae || 0, Ne || 0, qe, Ue, Ve));
                            break;
                        case l:
                            Fe.trigger("pinchOut", [We || null, Ae || 0, Ne || 0, qe, Ue, Ve]), t.pinchOut && (g = t.pinchOut.call(Fe, n, We || null, Ae || 0, Ne || 0, qe, Ue, Ve))
                    }
                }
                return c == f ? u !== D && u !== _ || (clearTimeout(Ke), clearTimeout(en), $() && !ee() ? (Xe = Ce(), Ke = setTimeout(e.proxy(function () {
                    Xe = null, Fe.trigger("tap", [n.target]), t.tap && (g = t.tap.call(Fe, n, n.target))
                }, this), t.doubleTapThreshold)) : (Xe = null, Fe.trigger("tap", [n.target]), t.tap && (g = t.tap.call(Fe, n, n.target)))) : c == p ? u !== D && u !== _ || (clearTimeout(Ke), Xe = null, Fe.trigger("doubletap", [n.target]), t.doubleTap && (g = t.doubleTap.call(Fe, n, n.target))) : c == m && (u !== D && u !== _ || (clearTimeout(Ke), Xe = null, Fe.trigger("longtap", [n.target]), t.longTap && (g = t.longTap.call(Fe, n, n.target)))), g
            }

            function N() {
                var e = !0;
                return null !== t.threshold && (e = Oe >= t.threshold), e
            }

            function H() {
                var e = !1;
                return null !== t.cancelThreshold && null !== Pe && (e = ge(Pe) - Oe >= t.cancelThreshold), e
            }

            function B() {
                return null === t.pinchThreshold || Ae >= t.pinchThreshold
            }

            function U() {
                var e;
                return e = !t.maxTimeThreshold || !(Ne >= t.maxTimeThreshold)
            }

            function A(e, n) {
                if (t.preventDefaultEvents !== !1)
                    if (t.allowPageScroll === u) e.preventDefault();
                    else {
                        var o = t.allowPageScroll === c;
                        switch (n) {
                            case a:
                                (t.swipeLeft && o || !o && t.allowPageScroll != g) && e.preventDefault();
                                break;
                            case i:
                                (t.swipeRight && o || !o && t.allowPageScroll != g) && e.preventDefault();
                                break;
                            case s:
                                (t.swipeUp && o || !o && t.allowPageScroll != v) && e.preventDefault();
                                break;
                            case r:
                                (t.swipeDown && o || !o && t.allowPageScroll != v) && e.preventDefault()
                        }
                    }
            }

            function W() {
                var e = Z(),
                    n = Q(),
                    t = B();
                return e && n && t
            }

            function G() {
                return !!(t.pinchStatus || t.pinchIn || t.pinchOut)
            }

            function F() {
                return !(!W() || !G())
            }

            function z() {
                var e = U(),
                    n = N(),
                    t = Z(),
                    a = Q(),
                    i = H(),
                    s = !i && a && t && n && e;
                return s
            }

            function q() {
                return !!(t.swipe || t.swipeStatus || t.swipeLeft || t.swipeRight || t.swipeUp || t.swipeDown)
            }

            function V() {
                return !(!z() || !q())
            }

            function Z() {
                return qe === t.fingers || t.fingers === y || !k
            }

            function Q() {
                return 0 !== Ve[0].end.x
            }

            function J() {
                return !!t.tap
            }

            function $() {
                return !!t.doubleTap
            }

            function X() {
                return !!t.longTap
            }

            function K() {
                if (null == Xe) return !1;
                var e = Ce();
                return $() && e - Xe <= t.doubleTapThreshold
            }

            function ee() {
                return K()
            }

            function ne() {
                return (1 === qe || !k) && (isNaN(Oe) || Oe < t.threshold)
            }

            function te() {
                return Ne > t.longTapThreshold && Oe < w
            }

            function ae() {
                return !(!ne() || !J())
            }

            function ie() {
                return !(!K() || !$())
            }

            function se() {
                return !(!te() || !X())
            }

            function re() {
                Je = Ce(), $e = event.touches.length + 1
            }

            function oe() {
                Je = 0, $e = 0
            }

            function le() {
                var e = !1;
                if (Je) {
                    var n = Ce() - Je;
                    n <= t.fingerReleaseThreshold && (e = !0)
                }
                return e
            }

            function ue() {
                return !(Fe.data(M + "_intouch") !== !0)
            }

            function ce(e) {
                e === !0 ? (Fe.bind(xe, I), Fe.bind(je, L), Ee && Fe.bind(Ee, j)) : (Fe.unbind(xe, I, !1), Fe.unbind(je, L, !1), Ee && Fe.unbind(Ee, j, !1)), Fe.data(M + "_intouch", e === !0)
            }

            function de(e, n) {
                var t = void 0 !== n.identifier ? n.identifier : 0;
                return Ve[e].identifier = t, Ve[e].start.x = Ve[e].end.x = n.pageX || n.clientX, Ve[e].start.y = Ve[e].end.y = n.pageY || n.clientY, Ve[e]
            }

            function he(e) {
                var n = void 0 !== e.identifier ? e.identifier : 0,
                    t = fe(n);
                return t.end.x = e.pageX || e.clientX, t.end.y = e.pageY || e.clientY, t
            }

            function fe(e) {
                for (var n = 0; n < Ve.length; n++)
                    if (Ve[n].identifier == e) return Ve[n]
            }

            function pe() {
                for (var e = [], n = 0; n <= 5; n++) e.push({
                    start: {
                        x: 0,
                        y: 0
                    },
                    end: {
                        x: 0,
                        y: 0
                    },
                    identifier: 0
                });
                return e
            }

            function me(e, n) {
                n = Math.max(n, ge(e)), Ge[e].distance = n
            }

            function ge(e) {
                if (Ge[e]) return Ge[e].distance
            }

            function ve() {
                var e = {};
                return e[a] = ye(a), e[i] = ye(i), e[s] = ye(s), e[r] = ye(r), e
            }

            function ye(e) {
                return {
                    direction: e,
                    distance: 0
                }
            }

            function we() {
                return Qe - Ze
            }

            function Te(e, n) {
                var t = Math.abs(e.x - n.x),
                    a = Math.abs(e.y - n.y);
                return Math.round(Math.sqrt(t * t + a * a))
            }

            function be(e, n) {
                var t = n / e * 1;
                return t.toFixed(2)
            }

            function _e() {
                return Ue < 1 ? l : o
            }

            function De(e, n) {
                return Math.round(Math.sqrt(Math.pow(n.x - e.x, 2) + Math.pow(n.y - e.y, 2)))
            }

            function ke(e, n) {
                var t = e.x - n.x,
                    a = n.y - e.y,
                    i = Math.atan2(a, t),
                    s = Math.round(180 * i / Math.PI);
                return s < 0 && (s = 360 - Math.abs(s)), s
            }

            function Se(e, n) {
                var t = ke(e, n);
                return t <= 45 && t >= 0 ? a : t <= 360 && t >= 315 ? a : t >= 135 && t <= 225 ? i : t > 45 && t < 135 ? r : s
            }

            function Ce() {
                var e = new Date;
                return e.getTime()
            }

            function Me(n) {
                n = e(n);
                var t = n.offset(),
                    a = {
                        left: t.left,
                        right: t.left + n.outerWidth(),
                        top: t.top,
                        bottom: t.top + n.outerHeight()
                    };
                return a
            }

            function Re(e, n) {
                return e.x > n.left && e.x < n.right && e.y > n.top && e.y < n.bottom
            }
            var Ie = k || C || !t.fallbackToMouseEvents,
                Le = Ie ? C ? S ? "MSPointerDown" : "pointerdown" : "touchstart" : "mousedown",
                xe = Ie ? C ? S ? "MSPointerMove" : "pointermove" : "touchmove" : "mousemove",
                je = Ie ? C ? S ? "MSPointerUp" : "pointerup" : "touchend" : "mouseup",
                Ee = Ie ? null : "mouseleave",
                Ye = C ? S ? "MSPointerCancel" : "pointercancel" : "touchcancel",
                Oe = 0,
                Pe = null,
                Ne = 0,
                He = 0,
                Be = 0,
                Ue = 1,
                Ae = 0,
                We = 0,
                Ge = null,
                Fe = e(n),
                ze = "start",
                qe = 0,
                Ve = null,
                Ze = 0,
                Qe = 0,
                Je = 0,
                $e = 0,
                Xe = 0,
                Ke = null,
                en = null;
            try {
                Fe.bind(Le, R), Fe.bind(Ye, x)
            } catch (nn) {
                e.error("events not supported " + Le + "," + Ye + " on jQuery.swipe")
            }
            this.enable = function () {
                return Fe.bind(Le, R), Fe.bind(Ye, x), Fe
            }, this.disable = function () {
                return E(), Fe
            }, this.destroy = function () {
                E(), Fe.data(M, null), Fe = null
            }, this.option = function (n, a) {
                if (void 0 !== t[n]) {
                    if (void 0 === a) return t[n];
                    t[n] = a
                } else e.error("Option " + n + " does not exist on jQuery.swipe.options");
                return null
            }
        }
        var a = "left",
            i = "right",
            s = "up",
            r = "down",
            o = "in",
            l = "out",
            u = "none",
            c = "auto",
            d = "swipe",
            h = "pinch",
            f = "tap",
            p = "doubletap",
            m = "longtap",
            g = "horizontal",
            v = "vertical",
            y = "all",
            w = 10,
            T = "start",
            b = "move",
            _ = "end",
            D = "cancel",
            k = "ontouchstart" in window,
            S = window.navigator.msPointerEnabled && !window.navigator.pointerEnabled,
            C = window.navigator.pointerEnabled || window.navigator.msPointerEnabled,
            M = "TouchSwipe",
            R = {
                fingers: 1,
                threshold: 75,
                cancelThreshold: null,
                pinchThreshold: 20,
                maxTimeThreshold: null,
                fingerReleaseThreshold: 250,
                longTapThreshold: 500,
                doubleTapThreshold: 200,
                swipe: null,
                swipeLeft: null,
                swipeRight: null,
                swipeUp: null,
                swipeDown: null,
                swipeStatus: null,
                pinchIn: null,
                pinchOut: null,
                pinchStatus: null,
                click: null,
                tap: null,
                doubleTap: null,
                longTap: null,
                hold: null,
                triggerOnTouchEnd: !0,
                triggerOnTouchLeave: !1,
                allowPageScroll: "auto",
                fallbackToMouseEvents: !0,
                excludedElements: "label, button, input, select, textarea, a, .noSwipe",
                preventDefaultEvents: !0
            };
        e.fn.swipe = function (t) {
            var a = e(this),
                i = a.data(M);
            if (i && "string" == typeof t) {
                if (i[t]) return i[t].apply(this, Array.prototype.slice.call(arguments, 1));
                e.error("Method " + t + " does not exist on jQuery.swipe")
            } else if (!(i || "object" != typeof t && t)) return n.apply(this, arguments);
            return a
        }, e.fn.swipe.defaults = R, e.fn.swipe.phases = {
            PHASE_START: T,
            PHASE_MOVE: b,
            PHASE_END: _,
            PHASE_CANCEL: D
        }, e.fn.swipe.directions = {
            LEFT: a,
            RIGHT: i,
            UP: s,
            DOWN: r,
            IN: o,
            OUT: l
        }, e.fn.swipe.pageScroll = {
            NONE: u,
            HORIZONTAL: g,
            VERTICAL: v,
            AUTO: c
        }, e.fn.swipe.fingers = {
            ONE: 1,
            TWO: 2,
            THREE: 3,
            ALL: y
        }
    }), window.XC_VM = window.XC_VM || {},
    function (e) {
        function n(e, n, t) {
            switch (arguments.length) {
                case 2:
                    return null != e ? e : n;
                case 3:
                    return null != e ? e : null != n ? n : t;
                default:
                    throw new Error("Implement me")
            }
        }

        function t(e, n) {
            return Me.call(e, n)
        }

        function a() {
            return {
                empty: !1,
                unusedTokens: [],
                unusedInput: [],
                overflow: -2,
                charsLeftOver: 0,
                nullInput: !1,
                invalidMonth: null,
                invalidFormat: !1,
                userInvalidated: !1,
                iso: !1
            }
        }

        function i(e) {
            be.suppressDeprecationWarnings === !1 && "undefined" != typeof console && console.warn && console.warn("Deprecation warning: " + e)
        }

        function s(e, n) {
            var t = !0;
            return p(function () {
                return t && (i(e), t = !1), n.apply(this, arguments)
            }, n)
        }

        function r(e, n) {
            wn[e] || (i(n), wn[e] = !0)
        }

        function o(e, n) {
            return function (t) {
                return v(e.call(this, t), n)
            }
        }

        function l(e, n) {
            return function (t) {
                return this.localeData().ordinal(e.call(this, t), n)
            }
        }

        function u(e, n) {
            var t, a, i = 12 * (n.year() - e.year()) + (n.month() - e.month()),
                s = e.clone().add(i, "months");
            return 0 > n - s ? (t = e.clone().add(i - 1, "months"), a = (n - s) / (s - t)) : (t = e.clone().add(i + 1, "months"), a = (n - s) / (t - s)), -(i + a)
        }

        function c(e, n, t) {
            var a;
            return null == t ? n : null != e.meridiemHour ? e.meridiemHour(n, t) : null != e.isPM ? (a = e.isPM(t), a && 12 > n && (n += 12), a || 12 !== n || (n = 0), n) : n
        }

        function d() { }

        function h(e, n) {
            n !== !1 && E(e), m(this, e), this._d = new Date((+e._d)), bn === !1 && (bn = !0, be.updateOffset(this), bn = !1)
        }

        function f(e) {
            var n = C(e),
                t = n.year || 0,
                a = n.quarter || 0,
                i = n.month || 0,
                s = n.week || 0,
                r = n.day || 0,
                o = n.hour || 0,
                l = n.minute || 0,
                u = n.second || 0,
                c = n.millisecond || 0;
            this._milliseconds = +c + 1e3 * u + 6e4 * l + 36e5 * o, this._days = +r + 7 * s, this._months = +i + 3 * a + 12 * t, this._data = {}, this._locale = be.localeData(), this._bubble()
        }

        function p(e, n) {
            for (var a in n) t(n, a) && (e[a] = n[a]);
            return t(n, "toString") && (e.toString = n.toString), t(n, "valueOf") && (e.valueOf = n.valueOf), e
        }

        function m(e, n) {
            var t, a, i;
            if ("undefined" != typeof n._isAMomentObject && (e._isAMomentObject = n._isAMomentObject), "undefined" != typeof n._i && (e._i = n._i), "undefined" != typeof n._f && (e._f = n._f), "undefined" != typeof n._l && (e._l = n._l), "undefined" != typeof n._strict && (e._strict = n._strict),
                "undefined" != typeof n._tzm && (e._tzm = n._tzm), "undefined" != typeof n._isUTC && (e._isUTC = n._isUTC), "undefined" != typeof n._offset && (e._offset = n._offset), "undefined" != typeof n._pf && (e._pf = n._pf), "undefined" != typeof n._locale && (e._locale = n._locale), Pe.length > 0)
                for (t in Pe) a = Pe[t], i = n[a], "undefined" != typeof i && (e[a] = i);
            return e
        }

        function g(e) {
            return 0 > e ? Math.ceil(e) : Math.floor(e)
        }

        function v(e, n, t) {
            for (var a = "" + Math.abs(e), i = e >= 0; a.length < n;) a = "0" + a;
            return (i ? t ? "+" : "" : "-") + a
        }

        function y(e, n) {
            var t = {
                milliseconds: 0,
                months: 0
            };
            return t.months = n.month() - e.month() + 12 * (n.year() - e.year()), e.clone().add(t.months, "M").isAfter(n) && --t.months, t.milliseconds = +n - +e.clone().add(t.months, "M"), t
        }

        function w(e, n) {
            var t;
            return n = H(n, e), e.isBefore(n) ? t = y(e, n) : (t = y(n, e), t.milliseconds = -t.milliseconds, t.months = -t.months), t
        }

        function T(e, n) {
            return function (t, a) {
                var i, s;
                return null === a || isNaN(+a) || (r(n, "moment()." + n + "(period, number) is deprecated. Please use moment()." + n + "(number, period)."), s = t, t = a, a = s), t = "string" == typeof t ? +t : t, i = be.duration(t, a), b(this, i, e), this
            }
        }

        function b(e, n, t, a) {
            var i = n._milliseconds,
                s = n._days,
                r = n._months;
            a = null == a || a, i && e._d.setTime(+e._d + i * t), s && me(e, "Date", pe(e, "Date") + s * t), r && fe(e, pe(e, "Month") + r * t), a && be.updateOffset(e, s || r)
        }

        function _(e) {
            return "[object Array]" === Object.prototype.toString.call(e)
        }

        function D(e) {
            return "[object Date]" === Object.prototype.toString.call(e) || e instanceof Date
        }

        function k(e, n, t) {
            var a, i = Math.min(e.length, n.length),
                s = Math.abs(e.length - n.length),
                r = 0;
            for (a = 0; i > a; a++)(t && e[a] !== n[a] || !t && R(e[a]) !== R(n[a])) && r++;
            return r + s
        }

        function S(e) {
            if (e) {
                var n = e.toLowerCase().replace(/(.)s$/, "$1");
                e = hn[e] || fn[n] || n
            }
            return e
        }

        function C(e) {
            var n, a, i = {};
            for (a in e) t(e, a) && (n = S(a), n && (i[n] = e[a]));
            return i
        }

        function M(n) {
            var t, a;
            if (0 === n.indexOf("week")) t = 7, a = "day";
            else {
                if (0 !== n.indexOf("month")) return;
                t = 12, a = "month"
            }
            be[n] = function (i, s) {
                var r, o, l = be._locale[n],
                    u = [];
                if ("number" == typeof i && (s = i, i = e), o = function (e) {
                    var n = be().utc().set(a, e);
                    return l.call(be._locale, n, i || "")
                }, null != s) return o(s);
                for (r = 0; t > r; r++) u.push(o(r));
                return u
            }
        }

        function R(e) {
            var n = +e,
                t = 0;
            return 0 !== n && isFinite(n) && (t = n >= 0 ? Math.floor(n) : Math.ceil(n)), t
        }

        function I(e, n) {
            return new Date(Date.UTC(e, n + 1, 0)).getUTCDate()
        }

        function L(e, n, t) {
            return ue(be([e, 11, 31 + n - t]), n, t).week
        }

        function x(e) {
            return j(e) ? 366 : 365
        }

        function j(e) {
            return e % 4 === 0 && e % 100 !== 0 || e % 400 === 0
        }

        function E(e) {
            var n;
            e._a && -2 === e._pf.overflow && (n = e._a[Ie] < 0 || e._a[Ie] > 11 ? Ie : e._a[Le] < 1 || e._a[Le] > I(e._a[Re], e._a[Ie]) ? Le : e._a[xe] < 0 || e._a[xe] > 24 || 24 === e._a[xe] && (0 !== e._a[je] || 0 !== e._a[Ee] || 0 !== e._a[Ye]) ? xe : e._a[je] < 0 || e._a[je] > 59 ? je : e._a[Ee] < 0 || e._a[Ee] > 59 ? Ee : e._a[Ye] < 0 || e._a[Ye] > 999 ? Ye : -1, e._pf._overflowDayOfYear && (Re > n || n > Le) && (n = Le), e._pf.overflow = n)
        }

        function Y(n) {
            return null == n._isValid && (n._isValid = !isNaN(n._d.getTime()) && n._pf.overflow < 0 && !n._pf.empty && !n._pf.invalidMonth && !n._pf.nullInput && !n._pf.invalidFormat && !n._pf.userInvalidated, n._strict && (n._isValid = n._isValid && 0 === n._pf.charsLeftOver && 0 === n._pf.unusedTokens.length && n._pf.bigHour === e)), n._isValid
        }

        function O(e) {
            return e ? e.toLowerCase().replace("_", "-") : e
        }

        function P(e) {
            for (var n, t, a, i, s = 0; s < e.length;) {
                for (i = O(e[s]).split("-"), n = i.length, t = O(e[s + 1]), t = t ? t.split("-") : null; n > 0;) {
                    if (a = N(i.slice(0, n).join("-"))) return a;
                    if (t && t.length >= n && k(i, t, !0) >= n - 1) break;
                    n--
                }
                s++
            }
            return null
        }

        function N(e) {
            var n = null;
            if (!Oe[e] && Ne) try {
                n = be.locale(), require("./locale/" + e), be.locale(n)
            } catch (t) { }
            return Oe[e]
        }

        function H(e, n) {
            var t, a;
            return n._isUTC ? (t = n.clone(), a = (be.isMoment(e) || D(e) ? +e : +be(e)) - +t, t._d.setTime(+t._d + a), be.updateOffset(t, !1), t) : be(e).local()
        }

        function B(e) {
            return e.match(/\[[\s\S]/) ? e.replace(/^\[|\]$/g, "") : e.replace(/\\/g, "")
        }

        function U(e) {
            var n, t, a = e.match(Ae);
            for (n = 0, t = a.length; t > n; n++) a[n] = yn[a[n]] ? yn[a[n]] : B(a[n]);
            return function (i) {
                var s = "";
                for (n = 0; t > n; n++) s += a[n] instanceof Function ? a[n].call(i, e) : a[n];
                return s
            }
        }

        function A(e, n) {
            return e.isValid() ? (n = W(n, e.localeData()), pn[n] || (pn[n] = U(n)), pn[n](e)) : e.localeData().invalidDate()
        }

        function W(e, n) {
            function t(e) {
                return n.longDateFormat(e) || e
            }
            var a = 5;
            for (We.lastIndex = 0; a >= 0 && We.test(e);) e = e.replace(We, t), We.lastIndex = 0, a -= 1;
            return e
        }

        function G(e, n) {
            var t, a = n._strict;
            switch (e) {
                case "Q":
                    return Ke;
                case "DDDD":
                    return nn;
                case "YYYY":
                case "GGGG":
                case "gggg":
                    return a ? tn : ze;
                case "Y":
                case "G":
                case "g":
                    return sn;
                case "YYYYYY":
                case "YYYYY":
                case "GGGGG":
                case "ggggg":
                    return a ? an : qe;
                case "S":
                    if (a) return Ke;
                case "SS":
                    if (a) return en;
                case "SSS":
                    if (a) return nn;
                case "DDD":
                    return Fe;
                case "MMM":
                case "MMMM":
                case "dd":
                case "ddd":
                case "dddd":
                    return Ze;
                case "a":
                case "A":
                    return n._locale._meridiemParse;
                case "x":
                    return $e;
                case "X":
                    return Xe;
                case "Z":
                case "ZZ":
                    return Qe;
                case "T":
                    return Je;
                case "SSSS":
                    return Ve;
                case "MM":
                case "DD":
                case "YY":
                case "GG":
                case "gg":
                case "HH":
                case "hh":
                case "mm":
                case "ss":
                case "ww":
                case "WW":
                    return a ? en : Ge;
                case "M":
                case "D":
                case "d":
                case "H":
                case "h":
                case "m":
                case "s":
                case "w":
                case "W":
                case "e":
                case "E":
                    return Ge;
                case "Do":
                    return a ? n._locale._ordinalParse : n._locale._ordinalParseLenient;
                default:
                    return t = new RegExp(X($(e.replace("\\", "")), "i"))
            }
        }

        function F(e) {
            e = e || "";
            var n = e.match(Qe) || [],
                t = n[n.length - 1] || [],
                a = (t + "").match(cn) || ["-", 0, 0],
                i = +(60 * a[1]) + R(a[2]);
            return "+" === a[0] ? i : -i
        }

        function z(e, n, t) {
            var a, i = t._a;
            switch (e) {
                case "Q":
                    null != n && (i[Ie] = 3 * (R(n) - 1));
                    break;
                case "M":
                case "MM":
                    null != n && (i[Ie] = R(n) - 1);
                    break;
                case "MMM":
                case "MMMM":
                    a = t._locale.monthsParse(n, e, t._strict), null != a ? i[Ie] = a : t._pf.invalidMonth = n;
                    break;
                case "D":
                case "DD":
                    null != n && (i[Le] = R(n));
                    break;
                case "Do":
                    null != n && (i[Le] = R(parseInt(n.match(/\d{1,2}/)[0], 10)));
                    break;
                case "DDD":
                case "DDDD":
                    null != n && (t._dayOfYear = R(n));
                    break;
                case "YY":
                    i[Re] = be.parseTwoDigitYear(n);
                    break;
                case "YYYY":
                case "YYYYY":
                case "YYYYYY":
                    i[Re] = R(n);
                    break;
                case "a":
                case "A":
                    t._meridiem = n;
                    break;
                case "h":
                case "hh":
                    t._pf.bigHour = !0;
                case "H":
                case "HH":
                    i[xe] = R(n);
                    break;
                case "m":
                case "mm":
                    i[je] = R(n);
                    break;
                case "s":
                case "ss":
                    i[Ee] = R(n);
                    break;
                case "S":
                case "SS":
                case "SSS":
                case "SSSS":
                    i[Ye] = R(1e3 * ("0." + n));
                    break;
                case "x":
                    t._d = new Date(R(n));
                    break;
                case "X":
                    t._d = new Date(1e3 * parseFloat(n));
                    break;
                case "Z":
                case "ZZ":
                    t._useUTC = !0, t._tzm = F(n);
                    break;
                case "dd":
                case "ddd":
                case "dddd":
                    a = t._locale.weekdaysParse(n), null != a ? (t._w = t._w || {}, t._w.d = a) : t._pf.invalidWeekday = n;
                    break;
                case "w":
                case "ww":
                case "W":
                case "WW":
                case "d":
                case "e":
                case "E":
                    e = e.substr(0, 1);
                case "gggg":
                case "GGGG":
                case "GGGGG":
                    e = e.substr(0, 2), n && (t._w = t._w || {}, t._w[e] = R(n));
                    break;
                case "gg":
                case "GG":
                    t._w = t._w || {}, t._w[e] = be.parseTwoDigitYear(n)
            }
        }

        function q(e) {
            var t, a, i, s, r, o, l;
            t = e._w, null != t.GG || null != t.W || null != t.E ? (r = 1, o = 4, a = n(t.GG, e._a[Re], ue(be(), 1, 4).year), i = n(t.W, 1), s = n(t.E, 1)) : (r = e._locale._week.dow, o = e._locale._week.doy, a = n(t.gg, e._a[Re], ue(be(), r, o).year), i = n(t.w, 1), null != t.d ? (s = t.d, r > s && ++i) : s = null != t.e ? t.e + r : r), l = ce(a, i, s, o, r), e._a[Re] = l.year, e._dayOfYear = l.dayOfYear
        }

        function V(e) {
            var t, a, i, s, r = [];
            if (!e._d) {
                for (i = Q(e), e._w && null == e._a[Le] && null == e._a[Ie] && q(e), e._dayOfYear && (s = n(e._a[Re], i[Re]), e._dayOfYear > x(s) && (e._pf._overflowDayOfYear = !0), a = se(s, 0, e._dayOfYear), e._a[Ie] = a.getUTCMonth(), e._a[Le] = a.getUTCDate()), t = 0; 3 > t && null == e._a[t]; ++t) e._a[t] = r[t] = i[t];
                for (; 7 > t; t++) e._a[t] = r[t] = null == e._a[t] ? 2 === t ? 1 : 0 : e._a[t];
                24 === e._a[xe] && 0 === e._a[je] && 0 === e._a[Ee] && 0 === e._a[Ye] && (e._nextDay = !0, e._a[xe] = 0), e._d = (e._useUTC ? se : ie).apply(null, r), null != e._tzm && e._d.setUTCMinutes(e._d.getUTCMinutes() - e._tzm), e._nextDay && (e._a[xe] = 24)
            }
        }

        function Z(e) {
            var n;
            e._d || (n = C(e._i), e._a = [n.year, n.month, n.day || n.date, n.hour, n.minute, n.second, n.millisecond], V(e))
        }

        function Q(e) {
            var n = new Date;
            return e._useUTC ? [n.getUTCFullYear(), n.getUTCMonth(), n.getUTCDate()] : [n.getFullYear(), n.getMonth(), n.getDate()]
        }

        function J(n) {
            if (n._f === be.ISO_8601) return void ee(n);
            n._a = [], n._pf.empty = !0;
            var t, a, i, s, r, o = "" + n._i,
                l = o.length,
                u = 0;
            for (i = W(n._f, n._locale).match(Ae) || [], t = 0; t < i.length; t++) s = i[t], a = (o.match(G(s, n)) || [])[0], a && (r = o.substr(0, o.indexOf(a)), r.length > 0 && n._pf.unusedInput.push(r), o = o.slice(o.indexOf(a) + a.length), u += a.length), yn[s] ? (a ? n._pf.empty = !1 : n._pf.unusedTokens.push(s), z(s, a, n)) : n._strict && !a && n._pf.unusedTokens.push(s);
            n._pf.charsLeftOver = l - u, o.length > 0 && n._pf.unusedInput.push(o), n._pf.bigHour === !0 && n._a[xe] <= 12 && (n._pf.bigHour = e), n._a[xe] = c(n._locale, n._a[xe], n._meridiem), V(n), E(n)
        }

        function $(e) {
            return e.replace(/\\(\[)|\\(\])|\[([^\]\[]*)\]|\\(.)/g, function (e, n, t, a, i) {
                return n || t || a || i
            })
        }

        function X(e) {
            return e.replace(/[-\/\\^$*+?.()|[\]{}]/g, "\\$&")
        }

        function K(e) {
            var n, t, i, s, r;
            if (0 === e._f.length) return e._pf.invalidFormat = !0, void (e._d = new Date(NaN));
            for (s = 0; s < e._f.length; s++) r = 0, n = m({}, e), null != e._useUTC && (n._useUTC = e._useUTC), n._pf = a(), n._f = e._f[s], J(n), Y(n) && (r += n._pf.charsLeftOver, r += 10 * n._pf.unusedTokens.length, n._pf.score = r, (null == i || i > r) && (i = r, t = n));
            p(e, t || n)
        }

        function ee(e) {
            var n, t, a = e._i,
                i = rn.exec(a);
            if (i) {
                for (e._pf.iso = !0, n = 0, t = ln.length; t > n; n++)
                    if (ln[n][1].exec(a)) {
                        e._f = ln[n][0] + (i[6] || " ");
                        break
                    } for (n = 0, t = un.length; t > n; n++)
                    if (un[n][1].exec(a)) {
                        e._f += un[n][0];
                        break
                    } a.match(Qe) && (e._f += "Z"), J(e)
            } else e._isValid = !1
        }

        function ne(e) {
            ee(e), e._isValid === !1 && (delete e._isValid, be.createFromInputFallback(e))
        }

        function te(e, n) {
            var t, a = [];
            for (t = 0; t < e.length; ++t) a.push(n(e[t], t));
            return a
        }

        function ae(n) {
            var t, a = n._i;
            a === e ? n._d = new Date : D(a) ? n._d = new Date((+a)) : null !== (t = He.exec(a)) ? n._d = new Date((+t[1])) : "string" == typeof a ? ne(n) : _(a) ? (n._a = te(a.slice(0), function (e) {
                return parseInt(e, 10)
            }), V(n)) : "object" == typeof a ? Z(n) : "number" == typeof a ? n._d = new Date(a) : be.createFromInputFallback(n)
        }

        function ie(e, n, t, a, i, s, r) {
            var o = new Date(e, n, t, a, i, s, r);
            return 1970 > e && o.setFullYear(e), o
        }

        function se(e) {
            var n = new Date(Date.UTC.apply(null, arguments));
            return 1970 > e && n.setUTCFullYear(e), n
        }

        function re(e, n) {
            if ("string" == typeof e)
                if (isNaN(e)) {
                    if (e = n.weekdaysParse(e), "number" != typeof e) return null
                } else e = parseInt(e, 10);
            return e
        }

        function oe(e, n, t, a, i) {
            return i.relativeTime(n || 1, !!t, e, a)
        }

        function le(e, n, t) {
            var a = be.duration(e).abs(),
                i = Ce(a.as("s")),
                s = Ce(a.as("m")),
                r = Ce(a.as("h")),
                o = Ce(a.as("d")),
                l = Ce(a.as("M")),
                u = Ce(a.as("y")),
                c = i < mn.s && ["s", i] || 1 === s && ["m"] || s < mn.m && ["mm", s] || 1 === r && ["h"] || r < mn.h && ["hh", r] || 1 === o && ["d"] || o < mn.d && ["dd", o] || 1 === l && ["M"] || l < mn.M && ["MM", l] || 1 === u && ["y"] || ["yy", u];
            return c[2] = n, c[3] = +e > 0, c[4] = t, oe.apply({}, c)
        }

        function ue(e, n, t) {
            var a, i = t - n,
                s = t - e.day();
            return s > i && (s -= 7), i - 7 > s && (s += 7), a = be(e).add(s, "d"), {
                week: Math.ceil(a.dayOfYear() / 7),
                year: a.year()
            }
        }

        function ce(e, n, t, a, i) {
            var s, r, o = se(e, 0, 1).getUTCDay();
            return o = 0 === o ? 7 : o, t = null != t ? t : i, s = i - o + (o > a ? 7 : 0) - (i > o ? 7 : 0), r = 7 * (n - 1) + (t - i) + s + 1, {
                year: r > 0 ? e : e - 1,
                dayOfYear: r > 0 ? r : x(e - 1) + r
            }
        }

        function de(n) {
            var t, a = n._i,
                i = n._f;
            return n._locale = n._locale || be.localeData(n._l), null === a || i === e && "" === a ? be.invalid({
                nullInput: !0
            }) : ("string" == typeof a && (n._i = a = n._locale.preparse(a)), be.isMoment(a) ? new h(a, (!0)) : (i ? _(i) ? K(n) : J(n) : ae(n), t = new h(n), t._nextDay && (t.add(1, "d"), t._nextDay = e), t))
        }

        function he(e, n) {
            var t, a;
            if (1 === n.length && _(n[0]) && (n = n[0]), !n.length) return be();
            for (t = n[0], a = 1; a < n.length; ++a) n[a][e](t) && (t = n[a]);
            return t
        }

        function fe(e, n) {
            var t;
            return "string" == typeof n && (n = e.localeData().monthsParse(n), "number" != typeof n) ? e : (t = Math.min(e.date(), I(e.year(), n)), e._d["set" + (e._isUTC ? "UTC" : "") + "Month"](n, t), e)
        }

        function pe(e, n) {
            return e._d["get" + (e._isUTC ? "UTC" : "") + n]()
        }

        function me(e, n, t) {
            return "Month" === n ? fe(e, t) : e._d["set" + (e._isUTC ? "UTC" : "") + n](t)
        }

        function ge(e, n) {
            return function (t) {
                return null != t ? (me(this, e, t), be.updateOffset(this, n), this) : pe(this, e)
            }
        }

        function ve(e) {
            return 400 * e / 146097
        }

        function ye(e) {
            return 146097 * e / 400
        }

        function we(e) {
            be.duration.fn[e] = function () {
                return this._data[e]
            }
        }

        function Te(e) {
            "undefined" == typeof ender && (_e = Se.moment, Se.moment = e ? s("Accessing Moment through the global scope is deprecated, and will be removed in an upcoming release.", be) : be)
        }
        for (var be, _e, De, ke = "2.9.0", Se = "undefined" == typeof global || "undefined" != typeof window && window !== global.window ? this : global, Ce = Math.round, Me = Object.prototype.hasOwnProperty, Re = 0, Ie = 1, Le = 2, xe = 3, je = 4, Ee = 5, Ye = 6, Oe = {}, Pe = [], Ne = "undefined" != typeof module && module && module.exports, He = /^\/?Date\((\-?\d+)/i, Be = /(\-)?(?:(\d*)\.)?(\d+)\:(\d+)(?:\:(\d+)\.?(\d{3})?)?/, Ue = /^(-)?P(?:(?:([0-9,.]*)Y)?(?:([0-9,.]*)M)?(?:([0-9,.]*)D)?(?:T(?:([0-9,.]*)H)?(?:([0-9,.]*)M)?(?:([0-9,.]*)S)?)?|([0-9,.]*)W)$/, Ae = /(\[[^\[]*\])|(\\)?(Mo|MM?M?M?|Do|DDDo|DD?D?D?|ddd?d?|do?|w[o|w]?|W[o|W]?|Q|YYYYYY|YYYYY|YYYY|YY|gg(ggg?)?|GG(GGG?)?|e|E|a|A|hh?|HH?|mm?|ss?|S{1,4}|x|X|zz?|ZZ?|.)/g, We = /(\[[^\[]*\])|(\\)?(LTS|LT|LL?L?L?|l{1,4})/g, Ge = /\d\d?/, Fe = /\d{1,3}/, ze = /\d{1,4}/, qe = /[+\-]?\d{1,6}/, Ve = /\d+/, Ze = /[0-9]*['a-z\u00A0-\u05FF\u0700-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+|[\u0600-\u06FF\/]+(\s*?[\u0600-\u06FF]+){1,2}/i, Qe = /Z|[\+\-]\d\d:?\d\d/gi, Je = /T/i, $e = /[\+\-]?\d+/, Xe = /[\+\-]?\d+(\.\d{1,3})?/, Ke = /\d/, en = /\d\d/, nn = /\d{3}/, tn = /\d{4}/, an = /[+-]?\d{6}/, sn = /[+-]?\d+/, rn = /^\s*(?:[+-]\d{6}|\d{4})-(?:(\d\d-\d\d)|(W\d\d$)|(W\d\d-\d)|(\d\d\d))((T| )(\d\d(:\d\d(:\d\d(\.\d+)?)?)?)?([\+\-]\d\d(?::?\d\d)?|\s*Z)?)?$/, on = "YYYY-MM-DDTHH:mm:ssZ", ln = [
            ["YYYYYY-MM-DD", /[+-]\d{6}-\d{2}-\d{2}/],
            ["YYYY-MM-DD", /\d{4}-\d{2}-\d{2}/],
            ["GGGG-[W]WW-E", /\d{4}-W\d{2}-\d/],
            ["GGGG-[W]WW", /\d{4}-W\d{2}/],
            ["YYYY-DDD", /\d{4}-\d{3}/]
        ], un = [
            ["HH:mm:ss.SSSS", /(T| )\d\d:\d\d:\d\d\.\d+/],
            ["HH:mm:ss", /(T| )\d\d:\d\d:\d\d/],
            ["HH:mm", /(T| )\d\d:\d\d/],
            ["HH", /(T| )\d\d/]
        ], cn = /([\+\-]|\d\d)/gi, dn = ("Date|Hours|Minutes|Seconds|Milliseconds".split("|"), {
            Milliseconds: 1,
            Seconds: 1e3,
            Minutes: 6e4,
            Hours: 36e5,
            Days: 864e5,
            Months: 2592e6,
            Years: 31536e6
        }), hn = {
            ms: "millisecond",
            s: "second",
            m: "minute",
            h: "hour",
            d: "day",
            D: "date",
            w: "week",
            W: "isoWeek",
            M: "month",
            Q: "quarter",
            y: "year",
            DDD: "dayOfYear",
            e: "weekday",
            E: "isoWeekday",
            gg: "weekYear",
            GG: "isoWeekYear"
        }, fn = {
            dayofyear: "dayOfYear",
            isoweekday: "isoWeekday",
            isoweek: "isoWeek",
            weekyear: "weekYear",
            isoweekyear: "isoWeekYear"
        }, pn = {}, mn = {
            s: 45,
            m: 45,
            h: 22,
            d: 26,
            M: 11
        }, gn = "DDD w W M D d".split(" "), vn = "M D H h m s w W".split(" "), yn = {
            M: function () {
                return this.month() + 1
            },
            MMM: function (e) {
                return this.localeData().monthsShort(this, e)
            },
            MMMM: function (e) {
                return this.localeData().months(this, e)
            },
            D: function () {
                return this.date()
            },
            DDD: function () {
                return this.dayOfYear()
            },
            d: function () {
                return this.day()
            },
            dd: function (e) {
                return this.localeData().weekdaysMin(this, e)
            },
            ddd: function (e) {
                return this.localeData().weekdaysShort(this, e)
            },
            dddd: function (e) {
                return this.localeData().weekdays(this, e)
            },
            w: function () {
                return this.week()
            },
            W: function () {
                return this.isoWeek()
            },
            YY: function () {
                return v(this.year() % 100, 2)
            },
            YYYY: function () {
                return v(this.year(), 4)
            },
            YYYYY: function () {
                return v(this.year(), 5)
            },
            YYYYYY: function () {
                var e = this.year(),
                    n = e >= 0 ? "+" : "-";
                return n + v(Math.abs(e), 6)
            },
            gg: function () {
                return v(this.weekYear() % 100, 2)
            },
            gggg: function () {
                return v(this.weekYear(), 4)
            },
            ggggg: function () {
                return v(this.weekYear(), 5)
            },
            GG: function () {
                return v(this.isoWeekYear() % 100, 2)
            },
            GGGG: function () {
                return v(this.isoWeekYear(), 4)
            },
            GGGGG: function () {
                return v(this.isoWeekYear(), 5)
            },
            e: function () {
                return this.weekday()
            },
            E: function () {
                return this.isoWeekday()
            },
            a: function () {
                return this.localeData().meridiem(this.hours(), this.minutes(), !0)
            },
            A: function () {
                return this.localeData().meridiem(this.hours(), this.minutes(), !1)
            },
            H: function () {
                return this.hours()
            },
            h: function () {
                return this.hours() % 12 || 12
            },
            m: function () {
                return this.minutes()
            },
            s: function () {
                return this.seconds()
            },
            S: function () {
                return R(this.milliseconds() / 100)
            },
            SS: function () {
                return v(R(this.milliseconds() / 10), 2)
            },
            SSS: function () {
                return v(this.milliseconds(), 3)
            },
            SSSS: function () {
                return v(this.milliseconds(), 3)
            },
            Z: function () {
                var e = this.utcOffset(),
                    n = "+";
                return 0 > e && (e = -e, n = "-"), n + v(R(e / 60), 2) + ":" + v(R(e) % 60, 2)
            },
            ZZ: function () {
                var e = this.utcOffset(),
                    n = "+";
                return 0 > e && (e = -e, n = "-"), n + v(R(e / 60), 2) + v(R(e) % 60, 2)
            },
            z: function () {
                return this.zoneAbbr()
            },
            zz: function () {
                return this.zoneName()
            },
            x: function () {
                return this.valueOf()
            },
            X: function () {
                return this.unix()
            },
            Q: function () {
                return this.quarter()
            }
        }, wn = {}, Tn = ["months", "monthsShort", "weekdays", "weekdaysShort", "weekdaysMin"], bn = !1; gn.length;) De = gn.pop(), yn[De + "o"] = l(yn[De], De);
        for (; vn.length;) De = vn.pop(), yn[De + De] = o(yn[De], 2);
        yn.DDDD = o(yn.DDD, 3), p(d.prototype, {
            set: function (e) {
                var n, t;
                for (t in e) n = e[t], "function" == typeof n ? this[t] = n : this["_" + t] = n;
                this._ordinalParseLenient = new RegExp(this._ordinalParse.source + "|" + /\d{1,2}/.source)
            },
            _months: "January_February_March_April_May_June_July_August_September_October_November_December".split("_"),
            months: function (e) {
                return this._months[e.month()]
            },
            _monthsShort: "Jan_Feb_Mar_Apr_May_Jun_Jul_Aug_Sep_Oct_Nov_Dec".split("_"),
            monthsShort: function (e) {
                return this._monthsShort[e.month()]
            },
            monthsParse: function (e, n, t) {
                var a, i, s;
                for (this._monthsParse || (this._monthsParse = [], this._longMonthsParse = [], this._shortMonthsParse = []), a = 0; 12 > a; a++) {
                    if (i = be.utc([2e3, a]), t && !this._longMonthsParse[a] && (this._longMonthsParse[a] = new RegExp("^" + this.months(i, "").replace(".", "") + "$", "i"), this._shortMonthsParse[a] = new RegExp("^" + this.monthsShort(i, "").replace(".", "") + "$", "i")), t || this._monthsParse[a] || (s = "^" + this.months(i, "") + "|^" + this.monthsShort(i, ""), this._monthsParse[a] = new RegExp(s.replace(".", ""), "i")), t && "MMMM" === n && this._longMonthsParse[a].test(e)) return a;
                    if (t && "MMM" === n && this._shortMonthsParse[a].test(e)) return a;
                    if (!t && this._monthsParse[a].test(e)) return a
                }
            },
            _weekdays: "Sunday_Monday_Tuesday_Wednesday_Thursday_Friday_Saturday".split("_"),
            weekdays: function (e) {
                return this._weekdays[e.day()]
            },
            _weekdaysShort: "Sun_Mon_Tue_Wed_Thu_Fri_Sat".split("_"),
            weekdaysShort: function (e) {
                return this._weekdaysShort[e.day()]
            },
            _weekdaysMin: "Su_Mo_Tu_We_Th_Fr_Sa".split("_"),
            weekdaysMin: function (e) {
                return this._weekdaysMin[e.day()]
            },
            weekdaysParse: function (e) {
                var n, t, a;
                for (this._weekdaysParse || (this._weekdaysParse = []), n = 0; 7 > n; n++)
                    if (this._weekdaysParse[n] || (t = be([2e3, 1]).day(n), a = "^" + this.weekdays(t, "") + "|^" + this.weekdaysShort(t, "") + "|^" + this.weekdaysMin(t, ""), this._weekdaysParse[n] = new RegExp(a.replace(".", ""), "i")), this._weekdaysParse[n].test(e)) return n
            },
            _longDateFormat: {
                LTS: "h:mm:ss A",
                LT: "h:mm A",
                L: "MM/DD/YYYY",
                LL: "MMMM D, YYYY",
                LLL: "MMMM D, YYYY LT",
                LLLL: "dddd, MMMM D, YYYY LT"
            },
            longDateFormat: function (e) {
                var n = this._longDateFormat[e];
                return !n && this._longDateFormat[e.toUpperCase()] && (n = this._longDateFormat[e.toUpperCase()].replace(/MMMM|MM|DD|dddd/g, function (e) {
                    return e.slice(1)
                }), this._longDateFormat[e] = n), n
            },
            isPM: function (e) {
                return "p" === (e + "").toLowerCase().charAt(0)
            },
            _meridiemParse: /[ap]\.?m?\.?/i,
            meridiem: function (e, n, t) {
                return e > 11 ? t ? "pm" : "PM" : t ? "am" : "AM"
            },
            _calendar: {
                sameDay: "[Today at] LT",
                nextDay: "[Tomorrow at] LT",
                nextWeek: "dddd [at] LT",
                lastDay: "[Yesterday at] LT",
                lastWeek: "[Last] dddd [at] LT",
                sameElse: "L"
            },
            calendar: function (e, n, t) {
                var a = this._calendar[e];
                return "function" == typeof a ? a.apply(n, [t]) : a
            },
            _relativeTime: {
                future: "in %s",
                past: "%s ago",
                s: "a few seconds",
                m: "a minute",
                mm: "%d minutes",
                h: "an hour",
                hh: "%d hours",
                d: "a day",
                dd: "%d days",
                M: "a month",
                MM: "%d months",
                y: "a year",
                yy: "%d years"
            },
            relativeTime: function (e, n, t, a) {
                var i = this._relativeTime[t];
                return "function" == typeof i ? i(e, n, t, a) : i.replace(/%d/i, e)
            },
            pastFuture: function (e, n) {
                var t = this._relativeTime[e > 0 ? "future" : "past"];
                return "function" == typeof t ? t(n) : t.replace(/%s/i, n)
            },
            ordinal: function (e) {
                return this._ordinal.replace("%d", e)
            },
            _ordinal: "%d",
            _ordinalParse: /\d{1,2}/,
            preparse: function (e) {
                return e
            },
            postformat: function (e) {
                return e
            },
            week: function (e) {
                return ue(e, this._week.dow, this._week.doy).week
            },
            _week: {
                dow: 0,
                doy: 6
            },
            firstDayOfWeek: function () {
                return this._week.dow
            },
            firstDayOfYear: function () {
                return this._week.doy
            },
            _invalidDate: "Invalid date",
            invalidDate: function () {
                return this._invalidDate
            }
        }), be = function (n, t, i, s) {
            var r;
            return "boolean" == typeof i && (s = i, i = e), r = {}, r._isAMomentObject = !0, r._i = n, r._f = t, r._l = i, r._strict = s, r._isUTC = !1, r._pf = a(), de(r)
        }, be.suppressDeprecationWarnings = !1, be.createFromInputFallback = s("moment construction falls back to js Date. This is discouraged and will be removed in upcoming major release. Please refer to https://github.com/moment/moment/issues/1407 for more info.", function (e) {
            e._d = new Date(e._i + (e._useUTC ? " UTC" : ""))
        }), be.min = function () {
            var e = [].slice.call(arguments, 0);
            return he("isBefore", e)
        }, be.max = function () {
            var e = [].slice.call(arguments, 0);
            return he("isAfter", e)
        }, be.utc = function (n, t, i, s) {
            var r;
            return "boolean" == typeof i && (s = i, i = e), r = {}, r._isAMomentObject = !0, r._useUTC = !0, r._isUTC = !0, r._l = i, r._i = n, r._f = t, r._strict = s, r._pf = a(), de(r).utc()
        }, be.unix = function (e) {
            return be(1e3 * e)
        }, be.duration = function (e, n) {
            var a, i, s, r, o = e,
                l = null;
            return be.isDuration(e) ? o = {
                ms: e._milliseconds,
                d: e._days,
                M: e._months
            } : "number" == typeof e ? (o = {}, n ? o[n] = e : o.milliseconds = e) : (l = Be.exec(e)) ? (a = "-" === l[1] ? -1 : 1, o = {
                y: 0,
                d: R(l[Le]) * a,
                h: R(l[xe]) * a,
                m: R(l[je]) * a,
                s: R(l[Ee]) * a,
                ms: R(l[Ye]) * a
            }) : (l = Ue.exec(e)) ? (a = "-" === l[1] ? -1 : 1, s = function (e) {
                var n = e && parseFloat(e.replace(",", "."));
                return (isNaN(n) ? 0 : n) * a
            }, o = {
                y: s(l[2]),
                M: s(l[3]),
                d: s(l[4]),
                h: s(l[5]),
                m: s(l[6]),
                s: s(l[7]),
                w: s(l[8])
            }) : null == o ? o = {} : "object" == typeof o && ("from" in o || "to" in o) && (r = w(be(o.from), be(o.to)), o = {}, o.ms = r.milliseconds, o.M = r.months), i = new f(o), be.isDuration(e) && t(e, "_locale") && (i._locale = e._locale), i
        }, be.version = ke, be.defaultFormat = on, be.ISO_8601 = function () { }, be.momentProperties = Pe, be.updateOffset = function () { }, be.relativeTimeThreshold = function (n, t) {
            return mn[n] !== e && (t === e ? mn[n] : (mn[n] = t, !0))
        }, be.lang = s("moment.lang is deprecated. Use moment.locale instead.", function (e, n) {
            return be.locale(e, n)
        }), be.locale = function (e, n) {
            var t;
            return e && (t = "undefined" != typeof n ? be.defineLocale(e, n) : be.localeData(e), t && (be.duration._locale = be._locale = t)), be._locale._abbr
        }, be.defineLocale = function (e, n) {
            return null !== n ? (n.abbr = e, Oe[e] || (Oe[e] = new d), Oe[e].set(n), be.locale(e), Oe[e]) : (delete Oe[e], null)
        }, be.langData = s("moment.langData is deprecated. Use moment.localeData instead.", function (e) {
            return be.localeData(e)
        }), be.localeData = function (e) {
            var n;
            if (e && e._locale && e._locale._abbr && (e = e._locale._abbr), !e) return be._locale;
            if (!_(e)) {
                if (n = N(e)) return n;
                e = [e]
            }
            return P(e)
        }, be.isMoment = function (e) {
            return e instanceof h || null != e && t(e, "_isAMomentObject")
        }, be.isDuration = function (e) {
            return e instanceof f
        };
        for (De = Tn.length - 1; De >= 0; --De) M(Tn[De]);
        be.normalizeUnits = function (e) {
            return S(e)
        }, be.invalid = function (e) {
            var n = be.utc(NaN);
            return null != e ? p(n._pf, e) : n._pf.userInvalidated = !0, n
        }, be.parseZone = function () {
            return be.apply(null, arguments).parseZone()
        }, be.parseTwoDigitYear = function (e) {
            return R(e) + (R(e) > 68 ? 1900 : 2e3)
        }, be.isDate = D, p(be.fn = h.prototype, {
            clone: function () {
                return be(this)
            },
            valueOf: function () {
                return +this._d - 6e4 * (this._offset || 0)
            },
            unix: function () {
                return Math.floor(+this / 1e3)
            },
            toString: function () {
                return this.clone().locale("en").format("ddd MMM DD YYYY HH:mm:ss [GMT]ZZ")
            },
            toDate: function () {
                return this._offset ? new Date((+this)) : this._d
            },
            toISOString: function () {
                var e = be(this).utc();
                return 0 < e.year() && e.year() <= 9999 ? "function" == typeof Date.prototype.toISOString ? this.toDate().toISOString() : A(e, "YYYY-MM-DD[T]HH:mm:ss.SSS[Z]") : A(e, "YYYYYY-MM-DD[T]HH:mm:ss.SSS[Z]")
            },
            toArray: function () {
                var e = this;
                return [e.year(), e.month(), e.date(), e.hours(), e.minutes(), e.seconds(), e.milliseconds()]
            },
            isValid: function () {
                return Y(this)
            },
            isDSTShifted: function () {
                return !!this._a && (this.isValid() && k(this._a, (this._isUTC ? be.utc(this._a) : be(this._a)).toArray()) > 0)
            },
            parsingFlags: function () {
                return p({}, this._pf)
            },
            invalidAt: function () {
                return this._pf.overflow
            },
            utc: function (e) {
                return this.utcOffset(0, e)
            },
            local: function (e) {
                return this._isUTC && (this.utcOffset(0, e), this._isUTC = !1, e && this.subtract(this._dateUtcOffset(), "m")), this
            },
            format: function (e) {
                var n = A(this, e || be.defaultFormat);
                return this.localeData().postformat(n)
            },
            add: T(1, "add"),
            subtract: T(-1, "subtract"),
            diff: function (e, n, t) {
                var a, i, s = H(e, this),
                    r = 6e4 * (s.utcOffset() - this.utcOffset());
                return n = S(n), "year" === n || "month" === n || "quarter" === n ? (i = u(this, s), "quarter" === n ? i /= 3 : "year" === n && (i /= 12)) : (a = this - s, i = "second" === n ? a / 1e3 : "minute" === n ? a / 6e4 : "hour" === n ? a / 36e5 : "day" === n ? (a - r) / 864e5 : "week" === n ? (a - r) / 6048e5 : a), t ? i : g(i)
            },
            from: function (e, n) {
                return be.duration({
                    to: this,
                    from: e
                }).locale(this.locale()).humanize(!n)
            },
            fromNow: function (e) {
                return this.from(be(), e)
            },
            calendar: function (e) {
                var n = e || be(),
                    t = H(n, this).startOf("day"),
                    a = this.diff(t, "days", !0),
                    i = -6 > a ? "sameElse" : -1 > a ? "lastWeek" : 0 > a ? "lastDay" : 1 > a ? "sameDay" : 2 > a ? "nextDay" : 7 > a ? "nextWeek" : "sameElse";
                return this.format(this.localeData().calendar(i, this, be(n)))
            },
            isLeapYear: function () {
                return j(this.year())
            },
            isDST: function () {
                return this.utcOffset() > this.clone().month(0).utcOffset() || this.utcOffset() > this.clone().month(5).utcOffset()
            },
            day: function (e) {
                var n = this._isUTC ? this._d.getUTCDay() : this._d.getDay();
                return null != e ? (e = re(e, this.localeData()), this.add(e - n, "d")) : n
            },
            month: ge("Month", !0),
            startOf: function (e) {
                switch (e = S(e)) {
                    case "year":
                        this.month(0);
                    case "quarter":
                    case "month":
                        this.date(1);
                    case "week":
                    case "isoWeek":
                    case "day":
                        this.hours(0);
                    case "hour":
                        this.minutes(0);
                    case "minute":
                        this.seconds(0);
                    case "second":
                        this.milliseconds(0)
                }
                return "week" === e ? this.weekday(0) : "isoWeek" === e && this.isoWeekday(1), "quarter" === e && this.month(3 * Math.floor(this.month() / 3)), this
            },
            endOf: function (n) {
                return n = S(n), n === e || "millisecond" === n ? this : this.startOf(n).add(1, "isoWeek" === n ? "week" : n).subtract(1, "ms")
            },
            isAfter: function (e, n) {
                var t;
                return n = S("undefined" != typeof n ? n : "millisecond"), "millisecond" === n ? (e = be.isMoment(e) ? e : be(e), +this > +e) : (t = be.isMoment(e) ? +e : +be(e), t < +this.clone().startOf(n))
            },
            isBefore: function (e, n) {
                var t;
                return n = S("undefined" != typeof n ? n : "millisecond"), "millisecond" === n ? (e = be.isMoment(e) ? e : be(e), +e > +this) : (t = be.isMoment(e) ? +e : +be(e), +this.clone().endOf(n) < t)
            },
            isBetween: function (e, n, t) {
                return this.isAfter(e, t) && this.isBefore(n, t)
            },
            isSame: function (e, n) {
                var t;
                return n = S(n || "millisecond"), "millisecond" === n ? (e = be.isMoment(e) ? e : be(e), +this === +e) : (t = +be(e), +this.clone().startOf(n) <= t && t <= +this.clone().endOf(n))
            },
            min: s("moment().min is deprecated, use moment.min instead. https://github.com/moment/moment/issues/1548", function (e) {
                return e = be.apply(null, arguments), this > e ? this : e
            }),
            max: s("moment().max is deprecated, use moment.max instead. https://github.com/moment/moment/issues/1548", function (e) {
                return e = be.apply(null, arguments), e > this ? this : e
            }),
            zone: s("moment().zone is deprecated, use moment().utcOffset instead. https://github.com/moment/moment/issues/1779", function (e, n) {
                return null != e ? ("string" != typeof e && (e = -e), this.utcOffset(e, n), this) : -this.utcOffset()
            }),
            utcOffset: function (e, n) {
                var t, a = this._offset || 0;
                return null != e ? ("string" == typeof e && (e = F(e)), Math.abs(e) < 16 && (e = 60 * e), !this._isUTC && n && (t = this._dateUtcOffset()), this._offset = e, this._isUTC = !0, null != t && this.add(t, "m"), a !== e && (!n || this._changeInProgress ? b(this, be.duration(e - a, "m"), 1, !1) : this._changeInProgress || (this._changeInProgress = !0, be.updateOffset(this, !0), this._changeInProgress = null)), this) : this._isUTC ? a : this._dateUtcOffset()
            },
            isLocal: function () {
                return !this._isUTC
            },
            isUtcOffset: function () {
                return this._isUTC
            },
            isUtc: function () {
                return this._isUTC && 0 === this._offset
            },
            zoneAbbr: function () {
                return this._isUTC ? "UTC" : ""
            },
            zoneName: function () {
                return this._isUTC ? "Coordinated Universal Time" : ""
            },
            parseZone: function () {
                return this._tzm ? this.utcOffset(this._tzm) : "string" == typeof this._i && this.utcOffset(F(this._i)), this
            },
            hasAlignedHourOffset: function (e) {
                return e = e ? be(e).utcOffset() : 0, (this.utcOffset() - e) % 60 === 0
            },
            daysInMonth: function () {
                return I(this.year(), this.month())
            },
            dayOfYear: function (e) {
                var n = Ce((be(this).startOf("day") - be(this).startOf("year")) / 864e5) + 1;
                return null == e ? n : this.add(e - n, "d")
            },
            quarter: function (e) {
                return null == e ? Math.ceil((this.month() + 1) / 3) : this.month(3 * (e - 1) + this.month() % 3)
            },
            weekYear: function (e) {
                var n = ue(this, this.localeData()._week.dow, this.localeData()._week.doy).year;
                return null == e ? n : this.add(e - n, "y")
            },
            isoWeekYear: function (e) {
                var n = ue(this, 1, 4).year;
                return null == e ? n : this.add(e - n, "y")
            },
            week: function (e) {
                var n = this.localeData().week(this);
                return null == e ? n : this.add(7 * (e - n), "d")
            },
            isoWeek: function (e) {
                var n = ue(this, 1, 4).week;
                return null == e ? n : this.add(7 * (e - n), "d")
            },
            weekday: function (e) {
                var n = (this.day() + 7 - this.localeData()._week.dow) % 7;
                return null == e ? n : this.add(e - n, "d")
            },
            isoWeekday: function (e) {
                return null == e ? this.day() || 7 : this.day(this.day() % 7 ? e : e - 7)
            },
            isoWeeksInYear: function () {
                return L(this.year(), 1, 4)
            },
            weeksInYear: function () {
                var e = this.localeData()._week;
                return L(this.year(), e.dow, e.doy)
            },
            get: function (e) {
                return e = S(e), this[e]()
            },
            set: function (e, n) {
                var t;
                if ("object" == typeof e)
                    for (t in e) this.set(t, e[t]);
                else e = S(e), "function" == typeof this[e] && this[e](n);
                return this
            },
            locale: function (n) {
                var t;
                return n === e ? this._locale._abbr : (t = be.localeData(n), null != t && (this._locale = t), this)
            },
            lang: s("moment().lang() is deprecated. Instead, use moment().localeData() to get the language configuration. Use moment().locale() to change languages.", function (n) {
                return n === e ? this.localeData() : this.locale(n)
            }),
            localeData: function () {
                return this._locale
            },
            _dateUtcOffset: function () {
                return 15 * -Math.round(this._d.getTimezoneOffset() / 15)
            }
        }), be.fn.millisecond = be.fn.milliseconds = ge("Milliseconds", !1), be.fn.second = be.fn.seconds = ge("Seconds", !1), be.fn.minute = be.fn.minutes = ge("Minutes", !1), be.fn.hour = be.fn.hours = ge("Hours", !0), be.fn.date = ge("Date", !0), be.fn.dates = s("dates accessor is deprecated. Use date instead.", ge("Date", !0)), be.fn.year = ge("FullYear", !0), be.fn.years = s("years accessor is deprecated. Use year instead.", ge("FullYear", !0)), be.fn.days = be.fn.day, be.fn.months = be.fn.month, be.fn.weeks = be.fn.week, be.fn.isoWeeks = be.fn.isoWeek, be.fn.quarters = be.fn.quarter, be.fn.toJSON = be.fn.toISOString, be.fn.isUTC = be.fn.isUtc, p(be.duration.fn = f.prototype, {
            _bubble: function () {
                var e, n, t, a = this._milliseconds,
                    i = this._days,
                    s = this._months,
                    r = this._data,
                    o = 0;
                r.milliseconds = a % 1e3, e = g(a / 1e3), r.seconds = e % 60, n = g(e / 60), r.minutes = n % 60, t = g(n / 60), r.hours = t % 24, i += g(t / 24), o = g(ve(i)), i -= g(ye(o)), s += g(i / 30), i %= 30, o += g(s / 12), s %= 12, r.days = i, r.months = s, r.years = o
            },
            abs: function () {
                return this._milliseconds = Math.abs(this._milliseconds), this._days = Math.abs(this._days), this._months = Math.abs(this._months), this._data.milliseconds = Math.abs(this._data.milliseconds), this._data.seconds = Math.abs(this._data.seconds), this._data.minutes = Math.abs(this._data.minutes), this._data.hours = Math.abs(this._data.hours), this._data.months = Math.abs(this._data.months), this._data.years = Math.abs(this._data.years), this
            },
            weeks: function () {
                return g(this.days() / 7)
            },
            valueOf: function () {
                return this._milliseconds + 864e5 * this._days + this._months % 12 * 2592e6 + 31536e6 * R(this._months / 12)
            },
            humanize: function (e) {
                var n = le(this, !e, this.localeData());
                return e && (n = this.localeData().pastFuture(+this, n)), this.localeData().postformat(n)
            },
            add: function (e, n) {
                var t = be.duration(e, n);
                return this._milliseconds += t._milliseconds, this._days += t._days, this._months += t._months, this._bubble(), this
            },
            subtract: function (e, n) {
                var t = be.duration(e, n);
                return this._milliseconds -= t._milliseconds, this._days -= t._days, this._months -= t._months, this._bubble(), this
            },
            get: function (e) {
                return e = S(e), this[e.toLowerCase() + "s"]()
            },
            as: function (e) {
                var n, t;
                if (e = S(e), "month" === e || "year" === e) return n = this._days + this._milliseconds / 864e5, t = this._months + 12 * ve(n), "month" === e ? t : t / 12;
                switch (n = this._days + Math.round(ye(this._months / 12)), e) {
                    case "week":
                        return n / 7 + this._milliseconds / 6048e5;
                    case "day":
                        return n + this._milliseconds / 864e5;
                    case "hour":
                        return 24 * n + this._milliseconds / 36e5;
                    case "minute":
                        return 24 * n * 60 + this._milliseconds / 6e4;
                    case "second":
                        return 24 * n * 60 * 60 + this._milliseconds / 1e3;
                    case "millisecond":
                        return Math.floor(24 * n * 60 * 60 * 1e3) + this._milliseconds;
                    default:
                        throw new Error("Unknown unit " + e)
                }
            },
            lang: be.fn.lang,
            locale: be.fn.locale,
            toIsoString: s("toIsoString() is deprecated. Please use toISOString() instead (notice the capitals)", function () {
                return this.toISOString()
            }),
            toISOString: function () {
                var e = Math.abs(this.years()),
                    n = Math.abs(this.months()),
                    t = Math.abs(this.days()),
                    a = Math.abs(this.hours()),
                    i = Math.abs(this.minutes()),
                    s = Math.abs(this.seconds() + this.milliseconds() / 1e3);
                return this.asSeconds() ? (this.asSeconds() < 0 ? "-" : "") + "P" + (e ? e + "Y" : "") + (n ? n + "M" : "") + (t ? t + "D" : "") + (a || i || s ? "T" : "") + (a ? a + "H" : "") + (i ? i + "M" : "") + (s ? s + "S" : "") : "P0D"
            },
            localeData: function () {
                return this._locale
            },
            toJSON: function () {
                return this.toISOString()
            }
        }), be.duration.fn.toString = be.duration.fn.toISOString;
        for (De in dn) t(dn, De) && we(De.toLowerCase());
        be.duration.fn.asMilliseconds = function () {
            return this.as("ms")
        }, be.duration.fn.asSeconds = function () {
            return this.as("s")
        }, be.duration.fn.asMinutes = function () {
            return this.as("m")
        }, be.duration.fn.asHours = function () {
            return this.as("h")
        }, be.duration.fn.asDays = function () {
            return this.as("d")
        }, be.duration.fn.asWeeks = function () {
            return this.as("weeks")
        }, be.duration.fn.asMonths = function () {
            return this.as("M")
        }, be.duration.fn.asYears = function () {
            return this.as("y")
        }, be.locale("en", {
            ordinalParse: /\d{1,2}(th|st|nd|rd)/,
            ordinal: function (e) {
                var n = e % 10,
                    t = 1 === R(e % 100 / 10) ? "th" : 1 === n ? "st" : 2 === n ? "nd" : 3 === n ? "rd" : "th";
                return e + t
            }
        }), Ne ? module.exports = be : "function" == typeof define && define.amd ? (define(function (e, n, t) {
            return t.config && t.config() && t.config().noGlobal === !0 && (Se.moment = _e), be
        }), Te(!0)) : Te()
    }.call(this), ! function (e) {
        if ("object" == typeof exports && "undefined" != typeof module) module.exports = e();
        else if ("function" == typeof define && define.amd) define([], e);
        else {
            var n;
            n = "undefined" != typeof window ? window : "undefined" != typeof global ? global : "undefined" != typeof self ? self : this, n.dragula = e()
        }
    }(function () {
        return function e(n, t, a) {
            function i(r, o) {
                if (!t[r]) {
                    if (!n[r]) {
                        var l = "function" == typeof require && require;
                        if (!o && l) return l(r, !0);
                        if (s) return s(r, !0);
                        var u = new Error("Cannot find module '" + r + "'");
                        throw u.code = "MODULE_NOT_FOUND", u
                    }
                    var c = t[r] = {
                        exports: {}
                    };
                    n[r][0].call(c.exports, function (e) {
                        var t = n[r][1][e];
                        return i(t ? t : e)
                    }, c, c.exports, e, n, t, a)
                }
                return t[r].exports
            }
            for (var s = "function" == typeof require && require, r = 0; r < a.length; r++) i(a[r]);
            return i
        }({
            1: [function (e, n, t) {
                "use strict";

                function a(e) {
                    var n = r[e];
                    return n ? n.lastIndex = 0 : r[e] = n = new RegExp(o + e + l, "g"), n
                }

                function i(e, n) {
                    var t = e.className;
                    t.length ? a(n).test(t) || (e.className += " " + n) : e.className = n
                }

                function s(e, n) {
                    e.className = e.className.replace(a(n), " ").trim()
                }
                var r = {},
                    o = "(?:^|\\s)",
                    l = "(?:\\s|$)";
                n.exports = {
                    add: i,
                    rm: s
                }
            }, {}],
            2: [function (e, n, t) {
                (function (t) {
                    "use strict";

                    function a(e, n) {
                        function t(e) {
                            return -1 !== te.containers.indexOf(e) || ne.isContainer(e)
                        }

                        function a(e) {
                            var n = e ? "remove" : "add";
                            i(K, n, "mousedown", w), i(K, n, "mouseup", M)
                        }

                        function r(e) {
                            var n = e ? "remove" : "add";
                            i(K, n, "mousemove", T)
                        }

                        function d(e) {
                            var n = e ? "remove" : "add";
                            i(K, n, "selectstart", y), i(K, n, "click", y)
                        }

                        function g() {
                            a(!0), M({})
                        }

                        function y(e) {
                            $ && e.preventDefault()
                        }

                        function w(e) {
                            var n = 0 !== e.which && 1 !== e.which || e.metaKey || e.ctrlKey;
                            if (!n) {
                                var t = e.target,
                                    a = b(t);
                                a && ($ = a, r(), "mousedown" === e.type && e.preventDefault())
                            }
                        }

                        function T(e) {
                            r(!0), d(), S(), D($);
                            var n = s(F);
                            z = h("pageX", e) - n.left, q = h("pageY", e) - n.top, v.add(Q || F, "gu-transit"), N(), Y(e)
                        }

                        function b(e) {
                            if (!(te.dragging && W || t(e))) {
                                for (var n = e; e.parentElement && t(e.parentElement) === !1;) {
                                    if (ne.invalid(e, n)) return;
                                    if (e = e.parentElement, !e) return
                                }
                                var a = e.parentElement;
                                if (a && !ne.invalid(e, n)) {
                                    var i = ne.moves(e, a, n);
                                    if (i) return {
                                        item: e,
                                        source: a
                                    }
                                }
                            }
                        }

                        function _(e) {
                            var n = b(e);
                            n && D(n)
                        }

                        function D(e) {
                            ne.copy && (Q = e.item.cloneNode(!0), te.emit("cloned", Q, e.item, "copy")), G = e.source, F = e.item, V = Z = c(e.item), te.dragging = !0, te.emit("drag", F, G)
                        }

                        function k() {
                            return !1
                        }

                        function S() {
                            if (te.dragging) {
                                var e = Q || F;
                                R(e, e.parentElement)
                            }
                        }

                        function C() {
                            $ = !1, r(!0), d(!0)
                        }

                        function M(e) {
                            if (C(), te.dragging) {
                                var n = Q || F,
                                    t = h("clientX", e),
                                    a = h("clientY", e),
                                    i = o(W, t, a),
                                    s = E(i, t, a);
                                !s || ne.copy !== !1 && s === G ? ne.removeOnSpill ? I() : L() : R(n, s)
                            }
                        }

                        function R(e, n) {
                            j(n) ? te.emit("cancel", e, G) : te.emit("drop", e, n, G), x()
                        }

                        function I() {
                            if (te.dragging) {
                                var e = Q || F,
                                    n = e.parentElement;
                                n && n.removeChild(e), te.emit(ne.copy ? "cancel" : "remove", e, n), x()
                            }
                        }

                        function L(e) {
                            if (te.dragging) {
                                var n = arguments.length > 0 ? e : ne.revertOnSpill,
                                    t = Q || F,
                                    a = t.parentElement;
                                a === G && ne.copy && a.removeChild(Q);
                                var i = j(a);
                                i === !1 && ne.copy === !1 && n && G.insertBefore(t, V), i || n ? te.emit("cancel", t, G) : te.emit("drop", t, a, G), x()
                            }
                        }

                        function x() {
                            var e = Q || F;
                            C(), H(), e && v.rm(e, "gu-transit"), J && clearTimeout(J), te.dragging = !1, te.emit("out", e, ee, G), te.emit("dragend", e), G = F = Q = V = Z = J = ee = null
                        }

                        function j(e, n) {
                            var t;
                            return t = void 0 !== n ? n : W ? Z : c(F || Q), e === G && t === V
                        }

                        function E(e, n, a) {
                            function i() {
                                var i = t(s);
                                if (i === !1) return !1;
                                var r = B(s, e),
                                    o = U(s, r, n, a),
                                    l = j(s, o);
                                return !!l || ne.accepts(F, s, G, o)
                            }
                            for (var s = e; s && !i();) s = s.parentElement;
                            return s
                        }

                        function Y(e) {
                            function n(e) {
                                te.emit(e, u, ee, G)
                            }

                            function t() {
                                p && n("over")
                            }

                            function a() {
                                ee && n("out")
                            }
                            if (W) {
                                e.preventDefault();
                                var i = h("clientX", e),
                                    s = h("clientY", e),
                                    r = i - z,
                                    l = s - q;
                                W.style.left = r + "px", W.style.top = l + "px";
                                var u = Q || F,
                                    d = o(W, i, s),
                                    f = E(d, i, s),
                                    p = null !== f && f !== ee;
                                if ((p || null === f) && (a(), ee = f, t()), f === G && ne.copy) return void (u.parentElement && u.parentElement.removeChild(u));
                                var m, g = B(f, d);
                                if (null !== g) m = U(f, g, i, s);
                                else {
                                    if (ne.revertOnSpill !== !0 || ne.copy) return void (ne.copy && u.parentElement && u.parentElement.removeChild(u));
                                    m = V, f = G
                                } (null === m || m !== u && m !== c(u) && m !== Z) && (Z = m, f.insertBefore(u, m), te.emit("shadow", u, f))
                            }
                        }

                        function O(e) {
                            v.rm(e, "gu-hide")
                        }

                        function P(e) {
                            te.dragging && v.add(e, "gu-hide")
                        }

                        function N() {
                            if (!W) {
                                var e = F.getBoundingClientRect();
                                W = F.cloneNode(!0), W.style.width = f(e) + "px", W.style.height = p(e) + "px", v.rm(W, "gu-transit"), v.add(W, "gu-mirror"), ne.mirrorContainer.appendChild(W), i(K, "add", "mousemove", Y), v.add(ne.mirrorContainer, "gu-unselectable"), te.emit("cloned", W, F, "mirror")
                            }
                        }

                        function H() {
                            W && (v.rm(ne.mirrorContainer, "gu-unselectable"), i(K, "remove", "mousemove", Y), W.parentElement.removeChild(W), W = null)
                        }

                        function B(e, n) {
                            for (var t = n; t !== e && t.parentElement !== e;) t = t.parentElement;
                            return t === K ? null : t
                        }

                        function U(e, n, t, a) {
                            function i() {
                                var n, i, s, r = e.children.length;
                                for (n = 0; r > n; n++) {
                                    if (i = e.children[n], s = i.getBoundingClientRect(), o && s.left > t) return i;
                                    if (!o && s.top > a) return i
                                }
                                return null
                            }

                            function s() {
                                var e = n.getBoundingClientRect();
                                return r(o ? t > e.left + f(e) / 2 : a > e.top + p(e) / 2)
                            }

                            function r(e) {
                                return e ? c(n) : n
                            }
                            var o = "horizontal" === ne.direction,
                                l = n !== e ? s() : i();
                            return l
                        }
                        var A = arguments.length;
                        1 === A && Array.isArray(e) === !1 && (n = e, e = []);
                        var W, G, F, z, q, V, Z, Q, J, $, X = document.body,
                            K = document.documentElement,
                            ee = null,
                            ne = n || {};
                        void 0 === ne.moves && (ne.moves = u), void 0 === ne.accepts && (ne.accepts = u), void 0 === ne.invalid && (ne.invalid = k), void 0 === ne.containers && (ne.containers = e || []), void 0 === ne.isContainer && (ne.isContainer = l), void 0 === ne.copy && (ne.copy = !1), void 0 === ne.revertOnSpill && (ne.revertOnSpill = !1), void 0 === ne.removeOnSpill && (ne.removeOnSpill = !1), void 0 === ne.direction && (ne.direction = "vertical"), void 0 === ne.mirrorContainer && (ne.mirrorContainer = X);
                        var te = m({
                            containers: ne.containers,
                            start: _,
                            end: S,
                            cancel: L,
                            remove: I,
                            destroy: g,
                            dragging: !1
                        });
                        return ne.removeOnSpill === !0 && te.on("over", O).on("out", P), a(), te
                    }

                    function i(e, n, a, i) {
                        var s = {
                            mouseup: "touchend",
                            mousedown: "touchstart",
                            mousemove: "touchmove"
                        },
                            r = {
                                mouseup: "MSPointerUp",
                                mousedown: "MSPointerDown",
                                mousemove: "MSPointerMove"
                            };
                        t.navigator.msPointerEnabled && g[n](e, r[a], i), g[n](e, s[a], i), g[n](e, a, i)
                    }

                    function s(e) {
                        var n = e.getBoundingClientRect();
                        return {
                            left: n.left + r("scrollLeft", "pageXOffset"),
                            top: n.top + r("scrollTop", "pageYOffset")
                        }
                    }

                    function r(e, n) {
                        if ("undefined" != typeof t[n]) return t[n];
                        var a = document.documentElement;
                        if (a.clientHeight) return a[e];
                        var i = document.body;
                        return i[e]
                    }

                    function o(e, n, t) {
                        if (!n && !t) return null;
                        var a, i = e || {},
                            s = i.className;
                        return i.className += " gu-hide", a = document.elementFromPoint(n, t), i.className = s, a
                    }

                    function l() {
                        return !1
                    }

                    function u() {
                        return !0
                    }

                    function c(e) {
                        function n() {
                            var n = e;
                            do n = n.nextSibling; while (n && 1 !== n.nodeType);
                            return n
                        }
                        return e.nextElementSibling || n()
                    }

                    function d(e) {
                        return e.targetTouches && e.targetTouches.length ? e.targetTouches[0] : e.changedTouches && e.changedTouches.length ? e.changedTouches[0] : e
                    }

                    function h(e, n) {
                        var t = d(n),
                            a = {
                                pageX: "clientX",
                                pageY: "clientY"
                            };
                        return e in a && !(e in t) && a[e] in t && (e = a[e]), t[e]
                    }

                    function f(e) {
                        return e.width || e.right - e.left
                    }

                    function p(e) {
                        return e.height || e.bottom - e.top
                    }
                    var m = e("contra/emitter"),
                        g = e("crossvent"),
                        v = e("./classes");
                    n.exports = a
                }).call(this, "undefined" != typeof global ? global : "undefined" != typeof self ? self : "undefined" != typeof window ? window : {})
            }, {
                "./classes": 1,
                "contra/emitter": 4,
                crossvent: 8
            }],
            3: [function (e, n, t) {
                "use strict";
                var a = e("ticky");
                n.exports = function (e, n, t) {
                    e && a(function () {
                        e.apply(t || null, n || [])
                    })
                }
            }, {
                ticky: 6
            }],
            4: [function (e, n, t) {
                "use strict";
                var a = e("atoa"),
                    i = e("./debounce");
                n.exports = function (e, n) {
                    var t = n || {},
                        s = {};
                    return void 0 === e && (e = {}), e.on = function (n, t) {
                        return s[n] ? s[n].push(t) : s[n] = [t], e
                    }, e.once = function (n, t) {
                        return t._once = !0, e.on(n, t), e
                    }, e.off = function (n, t) {
                        var a = arguments.length;
                        if (1 === a) delete s[n];
                        else if (0 === a) s = {};
                        else {
                            var i = s[n];
                            if (!i) return e;
                            i.splice(i.indexOf(t), 1)
                        }
                        return e
                    }, e.emit = function () {
                        var n = a(arguments);
                        return e.emitterSnapshot(n.shift()).apply(this, n)
                    }, e.emitterSnapshot = function (n) {
                        var r = (s[n] || []).slice(0);
                        return function () {
                            var s = a(arguments),
                                o = this || e;
                            if ("error" === n && t["throws"] !== !1 && !r.length) throw 1 === s.length ? s[0] : s;
                            return r.forEach(function (a) {
                                t.async ? i(a, s, o) : a.apply(o, s), a._once && e.off(n, a)
                            }), e
                        }
                    }, e
                }
            }, {
                "./debounce": 3,
                atoa: 5
            }],
            5: [function (e, n, t) {
                n.exports = function (e, n) {
                    return Array.prototype.slice.call(e, n)
                }
            }, {}],
            6: [function (e, n, t) {
                var a, i = "function" == typeof setImmediate;
                a = i ? function (e) {
                    setImmediate(e)
                } : function (e) {
                    setTimeout(e, 0)
                }, n.exports = a
            }, {}],
            7: [function (e, n, t) {
                (function (e) {
                    function t() {
                        try {
                            var e = new a("cat", {
                                detail: {
                                    foo: "bar"
                                }
                            });
                            return "cat" === e.type && "bar" === e.detail.foo
                        } catch (n) { }
                        return !1
                    }
                    var a = e.CustomEvent;
                    n.exports = t() ? a : "function" == typeof document.createEvent ? function (e, n) {
                        var t = document.createEvent("CustomEvent");
                        return n ? t.initCustomEvent(e, n.bubbles, n.cancelable, n.detail) : t.initCustomEvent(e, !1, !1, void 0), t
                    } : function (e, n) {
                        var t = document.createEventObject();
                        return t.type = e, n ? (t.bubbles = Boolean(n.bubbles), t.cancelable = Boolean(n.cancelable), t.detail = n.detail) : (t.bubbles = !1, t.cancelable = !1, t.detail = void 0), t
                    }
                }).call(this, "undefined" != typeof global ? global : "undefined" != typeof self ? self : "undefined" != typeof window ? window : {})
            }, {}],
            8: [function (e, n, t) {
                (function (t) {
                    "use strict";

                    function a(e, n, t, a) {
                        return e.addEventListener(n, t, a)
                    }

                    function i(e, n, t) {
                        return e.attachEvent("on" + n, u(e, n, t))
                    }

                    function s(e, n, t, a) {
                        return e.removeEventListener(n, t, a)
                    }

                    function r(e, n, t) {
                        return e.detachEvent("on" + n, c(e, n, t))
                    }

                    function o(e, n, t) {
                        function a() {
                            var e;
                            return p.createEvent ? (e = p.createEvent("Event"), e.initEvent(n, !0, !0)) : p.createEventObject && (e = p.createEventObject()), e
                        }

                        function i() {
                            return new h(n, {
                                detail: t
                            })
                        }
                        var s = -1 === f.indexOf(n) ? i() : a();
                        e.dispatchEvent ? e.dispatchEvent(s) : e.fireEvent("on" + n, s)
                    }

                    function l(e, n, a) {
                        return function (n) {
                            var i = n || t.event;
                            i.target = i.target || i.srcElement, i.preventDefault = i.preventDefault || function () {
                                i.returnValue = !1
                            }, i.stopPropagation = i.stopPropagation || function () {
                                i.cancelBubble = !0
                            }, i.which = i.which || i.keyCode, a.call(e, i)
                        }
                    }

                    function u(e, n, t) {
                        var a = c(e, n, t) || l(e, n, t);
                        return v.push({
                            wrapper: a,
                            element: e,
                            type: n,
                            fn: t
                        }), a
                    }

                    function c(e, n, t) {
                        var a = d(e, n, t);
                        if (a) {
                            var i = v[a].wrapper;
                            return v.splice(a, 1), i
                        }
                    }

                    function d(e, n, t) {
                        var a, i;
                        for (a = 0; a < v.length; a++)
                            if (i = v[a], i.element === e && i.type === n && i.fn === t) return a
                    }
                    var h = e("custom-event"),
                        f = e("./eventmap"),
                        p = document,
                        m = a,
                        g = s,
                        v = [];
                    t.addEventListener || (m = i, g = r), n.exports = {
                        add: m,
                        remove: g,
                        fabricate: o
                    }
                }).call(this, "undefined" != typeof global ? global : "undefined" != typeof self ? self : "undefined" != typeof window ? window : {})
            }, {
                "./eventmap": 9,
                "custom-event": 7
            }],
            9: [function (e, n, t) {
                (function (e) {
                    "use strict";
                    var t = [],
                        a = "",
                        i = /^on/;
                    for (a in e) i.test(a) && t.push(a.slice(2));
                    n.exports = t
                }).call(this, "undefined" != typeof global ? global : "undefined" != typeof self ? self : "undefined" != typeof window ? window : {})
            }, {}]
        }, {}, [2])(2)
    }), window.XC_VM.Listings.CookieSettings = function (e) {
        var n = {};
        window.location.href;
        return e.cookie.raw = !0, n.specialisation = function () {
            return "tv"
        }, n.personalisation = function (n) {
            return {
                channels: window.XC_VM.Listings.DefaultChannels
            };
        }, n.getUpdatedChannels = function (e) {
            var t = n.personalisation(e),
                a = t.channels;
            return a
        }, n
    }(jQuery), window.XC_VM = window.XC_VM || {}, window.XC_VM.Listings = window.XC_VM.Listings || {}, window.XC_VM = window.XC_VM || {}, window.XC_VM.Listings = window.XC_VM.Listings || {}, window.XC_VM.Listings.Settings = function (e, n) {
        var t, a, i, s, r, o, l, u, c, d, h, f, p, m, g, v, y, w, T, b, _, D = e(window),
            k = e("html, body"),
            S = e(".js-settings-btn"),
            C = e(".js-settings-save-top"),
            M = e(".js-settings-pnl"),
            R = e(".js-settings-outer-platform-pnl"),
            I = e(".js-settings-hdr"),
            L = e(".js-listings-sort-msg"),
            x = "tv",
            j = "TvChannels",
            E = "hideOrganiseChannelsMsg",
            Y = !window.XC_VM.utilities.isTouch() && !e.cookie(E),
            O = XC_VM.Listings.CookieSettings.personalisation(j),
            P = false,
            N = "settings",
            H = "/im-broadcast-listings-api/setusertvchannels",
            B = (O.bbcRegion || "bbc london").toLowerCase(),
            U = (O.itvRegion || "itv london").toLowerCase(),
            A = (O.platform || "popular channels").toLowerCase(),
            W = 0,
            G = {
                isFirstLoad: !0,
                regionIsDefaultTab: !0,
                isTv: "tv" === x,
                resetChannelOrder: !1,
                allMainChannelsSelected: !0
            },
            F = {
                BBC: "bbc",
                ITV: "itv"
            },
            z = {
                MAIN: "main",
                OTHER: "other"
            },
            q = function () {
                t = e(".js-settings-tab-link"), a = e(".js-settings-tab"), i = e(".js-bbc-region-btn"), s = e(".js-itv-region-btn"), r = e(".js-other-channel-section"), o = e(".js-toggle-other-channels"), l = e(".js-hide-other-channels"), u = e(".js-settings-toggle-all"), c = e(".js-settings-main-channels, .js-settings-other-channels"), d = e(".js-settings-main-channels"), h = e(".js-settings-other-channels"), f = e(".js-platform-menu-toggle"), p = e(".js-platform-menu"), m = e(".js-platform-menu-arrow"), v = e(".js-settings-platform"), g = e(".js-platform-menu-text"), y = e(".js-settings-footer"), w = e(".js-settings-reset-channels"), T = e(".js-settings-cancel"), b = e(".js-settings-save"), _ = e(".js-banner-ad")
            },
            V = function () {
                X(), K(), ee(), ne(), te(), ae(), ie(), se(), re(), oe()
            },
            Z = function () {
                D.on("scroll.settings", function () {
                    ue()
                })
            },
            Q = function () {
                D.off("scroll.settings")
            },
            J = function (e) {
                if (!(window.innerWidth >= 768)) switch (e) {
                    case "show":
                        _.css("left", "0");
                        break;
                    case "hide":
                        _.css("left", "-99999px")
                }
            },
            $ = function () {
                S.on("click", function (e) {
                    e.preventDefault(), S.toggleClass("btn-close"), S.find(".js-toggle-text").toggle(), C.toggleClass("active"), M.toggle(), R.toggle(), M.is(":visible") ? (Z(), J("hide")) : (Q(), He(), J("show"))
                })
            },
            X = function () {
                t.on("click", function (n) {
                    n.preventDefault();
                    var i = e(this);
                    t.removeClass("active"), i.addClass("active"), a.removeClass("active"), e(i.attr("href")).addClass("active")
                })
            },
            K = function () {
                i.on("click", function (n) {
                    n.preventDefault();
                    var t = e(this);
                    i.removeClass("selected"), t.addClass("selected"), me(F.BBC, t.data("region")), Ie(F.BBC), _e(!0)
                }), s.on("click", function (n) {
                    n.preventDefault();
                    var t = e(this);
                    s.removeClass("selected"), t.addClass("selected"), me(F.ITV, t.data("region")), Ie(F.ITV), _e(!0)
                })
            },
            ee = function () {
                o.on("click", function (n) {
                    n.preventDefault(), r.toggle(), r.is(":visible") ? (W = e(window).scrollTop(), Ne(r.offset().top - 20)) : Ne(W), o.find(".js-toggle-text").toggle()
                }), l.on("click", function (e) {
                    e.preventDefault(), r.hide(), o.find(".js-toggle-text").toggle(), ue()
                })
            },
            ne = function () {
                c.on("click", ".js-settings-channel", function (n) {
                    n.preventDefault();
                    var t = e(this);
                    t.toggleClass("selected");
                    var a = Se(t.data("channel-type"), t.data("channel-idx"));
                    a.Selected = !a.Selected, be(), De()
                })
            },
            te = function () {
                u.on("click", function (e) {
                    e.preventDefault(), G.allMainChannelsSelected = !G.allMainChannelsSelected, De(), ke()
                })
            },
            ae = function () {
                f.on("click", function (e) {
                    e.preventDefault(), p.toggleClass("open"), m.toggleClass("isvg-caret-down").toggleClass("isvg-caret-up")
                })
            },
            ie = function () {
            },
            se = function () {
                w.on("click", function (e) {
                    e.preventDefault(), _e(!G.resetChannelOrder)
                })
            },
            re = function () {
                T.on("click", function (e) {
                    e.preventDefault(), Q(), le()
                })
            },
            oe = function () {
                b.on("click", function (e) {
                    e.preventDefault(), Ee(), Q(), le(), _e(!1), Pe()
                })
            },
            le = function () {
                S.removeClass("btn-close"), S.find(".js-toggle-text").toggle(), C.removeClass("active"), y.removeClass("fixed"), M.hide(), R.show(), Ne(I.position().top), He(), J("show")
            },
            ue = function () {
                var e = D.scrollTop(),
                    n = M.position().top,
                    t = n + M.height() - D.height(),
                    a = e >= n && e <= t;
                y.toggleClass("fixed", a)
            },
            ce = function (n) {
                e.getJSON(xe()).done(function (e) {
                    G.isFirstLoad ? (S.addClass("active"), G.isFirstLoad = !1, de(e), Ce(), G.isTv && Me(), q(), V()) : (he(e), n && (Ee(), _e(!1), Pe()))
                }).fail(function () { })
            },
            de = function (n) {
                G = e.extend(!0, G, n), me(F.BBC, B), me(F.ITV, U), ve(A), G.MainChannels = fe(G.MainChannels, B, U), G.OtherChannels = fe(G.OtherChannels, B, U), Te(), be()
            },
            he = function (e) {
                var n = pe(F.BBC),
                    t = pe(F.ITV);
                G.MainChannels = fe(e.MainChannels, n.Region, t.Region), G.OtherChannels = fe(e.OtherChannels, n.Region, t.Region), Te(!0), Re(), be(), De()
            },
            fe = function (e, n, t) {
                n = n.toLowerCase(), t = t.toLowerCase();
                var a = e.filter(function (e) {
                    var a = e.Region.toLowerCase();
                    return "" === a || a === n || a === t
                });
                return a
            },
            pe = function (e) {
                for (var n = e === F.BBC ? G.BbcRegions : G.ItvRegions, t = 0, a = n.length; t < a; t++)
                    if (n[t].Selected) return n[t]
            },
            me = function (e, n) {
                e === F.BBC ? G.BbcRegions.forEach(function (e) {
                    e.Selected = e.Region.toLowerCase() === n.toLowerCase()
                }) : e === F.ITV && G.ItvRegions.forEach(function (e) {
                    e.Selected = e.Region.toLowerCase() === n.toLowerCase()
                })
            },
            ge = function () {
                if (G.Platforms)
                    for (var e = 0, n = G.Platforms.length; e < n; e++) {
                        var t = G.Platforms[e];
                        if (t.Selected) return t.Name
                    }
                return A
            },
            ve = function (e) {
                G.Platforms.forEach(function (n) {
                    n.Selected = n.Name.toLowerCase() === e.toLowerCase(), n.Selected && (G.currentPlatformName = n.Name)
                })
            },
            ye = function (n) {
                v.each(function () {
                    var t = e(this);
                    t.toggleClass("selected", t.data("platform") === n)
                }), p.removeClass("open"), m.removeClass("isvg-caret-up").addClass("isvg-caret-down"), g.text(n)
            },
            we = function () {
                var e = G.MainChannels.concat(G.OtherChannels);
                return e.filter(function (e) {
                    return e.Selected
                })
            },
            Te = function (e) {
                var n = O.channels.split(",");
                G.MainChannels.forEach(function (t) {
                    t.Selected = !!e || n.indexOf(String(t.Id)) !== -1
                }), G.OtherChannels.forEach(function (t) {
                    t.Selected = !e && n.indexOf(String(t.Id)) !== -1
                })
            },
            be = function () {
                G.allMainChannelsSelected = !0;
                for (var e = 0, n = G.MainChannels.length; e < n; e++) {
                    var t = G.MainChannels[e];
                    if (!t.Selected) {
                        G.allMainChannelsSelected = !1;
                        break
                    }
                }
            },
            _e = function (e) {
                w.toggleClass("selected", e), G.resetChannelOrder = e
            },
            De = function () {
                u.toggleClass("selected", G.allMainChannelsSelected)
            },
            ke = function () {
                d.find(".js-settings-channel").toggleClass("selected", G.allMainChannelsSelected), G.MainChannels.forEach(function (e) {
                    e.Selected = G.allMainChannelsSelected
                })
            },
            Se = function (e, n) {
                var t = e === z.MAIN ? G.MainChannels : G.OtherChannels;
                return t[n]
            },
            Ce = function () { },
            Me = function () { },
            Re = function () {
                var e = {
                    channels: G.MainChannels,
                    channelType: z.MAIN
                },
                    n = {
                        channels: G.OtherChannels,
                        channelType: z.OTHER
                    };
                d.empty(), h.empty()
            },
            Ie = function (n) {
                for (var t = pe(n), a = 0, i = t.Channels.length; a < i; a++) {
                    var s = t.Channels[a];
                    if ("" !== s.Position) {
                        var r = e(".js-pos-" + s.Position);
                        if (0 !== r.length) {
                            r.find(".js-channel-name").text(s.Name);
                            var o = Se(r.data("channel-type"), r.data("channel-idx"));
                            o.Id = s.Id, o.Name = s.Name, o.Region = t.Region
                        }
                    }
                }
            },
            Le = function () {
                "tv" === x ? O.bbcRegion && O.itvRegion && (G.regionIsDefaultTab = !1) : G.regionIsDefaultTab = !1
            },
            xe = function () {
                return N + "?media=" + x + "&platform=" + ge()
            },
            je = function () {
                var e = we(),
                    n = e.map(function (e) {
                        return e.Id
                    });
                if (G.resetChannelOrder) return n.join(",");
                O = XC_VM.Listings.CookieSettings.personalisation(j);
                var t = O.channels.split(",");
                t = t.map(function (e) {
                    return parseInt(e, 10)
                });
                var a = t.filter(function (e) {
                    return n.indexOf(e) !== -1
                }),
                    i = n.filter(function (e) {
                        return t.indexOf(e) === -1
                    });
                return a.push.apply(a, i), a.join(",")
            },
            Ee = function () {
                var e;
                e = G.isTv ? {
                    channels: je(),
                    bbcRegion: pe(F.BBC).Region,
                    itvRegion: pe(F.ITV).Region,
                    platform: ge()
                } : {
                    channels: je()
                }, Ye(e), Oe()
            },
            Ye = function (n) {
                var t = decodeURIComponent(e.param(n));
                e.removeCookie(j, {
                    path: "/"
                }), e.cookie(j, t, {
                    expires: 365,
                    path: "/"
                })
            },
            Oe = function () {
                P && e.ajax({
                    type: "POST",
                    url: H
                })
            },
            Pe = function () {
                var e = window.XC_VM.Listings.Grid.getStartDateTime();
                window.XC_VM.Listings.Grid.refreshGrid(e, !0)
            },
            Ne = function (e) {
                k.scrollTop(e)
            },
            He = function () {
                Y && (Y = !1, L.show(), L.click(function () {
                    e.cookie(E, "true"), L.hide()
                }))
            };
        return {
            init: function () {
                Le(), $()
            }
        }
    }(jQuery, window.XC_VM.EnvConfigHelper), window.XC_VM = window.XC_VM || {}, window.XC_VM.Listings = window.XC_VM.Listings || {}, window.XC_VM.Listings.TimeSelector = function (e) {
        var n = {},
            t = {
                container: ".listings-time-selector",
                scroller: ".js-time-selector-scroller",
                nav: ".js-time-selector-nav",
                timeItem: ".js-time-item",
                scrollDistance: 360
            },
            a = function (e, t) {
                n = {};
                for (var a = 0; a < t; a++) {
                    var i = "selected";
                    0 == a && (i += " first"), a == t - 1 && (i += " last"), n[e.hour()] = i, e.add(1, "h")
                }
            },
            i = function () {
                e(t.timeItem).on("click", function () {
                    XC_VM.Listings.Nav.jumpToTime(e(this))
                }), e(t.nav).on("click", function () {
                    e(t.scroller).animate({
                        scrollLeft: e(this).data("dir") + "=" + t.scrollDistance
                    })
                })
            },
            s = {};
        return s.init = function () {
            var r = moment(XC_VM.Listings.Grid.getStartDateTime()),
                o = XC_VM.Listings.Grid.getHours();
            a(r, o);
            for (var l = moment().startOf("day"), u = [], c = 0; c < 24; c++) u.push({
                displayHour: l.format("h"),
                trueHour: l.format("H"),
                meridiem: l.format("A").toUpperCase(),
                midday: 12 == l.hour(),
                showMeridiem: true,
                state: n[c] || ""
            }), l.add(1, "h");
            var d = Handlebars.templates.timeSelector({
                times: u
            });
            e(t.container).empty().append(d), i(), s.updateScrollPosition()
        }, s.updateHighlighting = function () {
            var i = moment(XC_VM.Listings.Grid.getStartDateTime()),
                s = XC_VM.Listings.Grid.getHours();
            a(i, s), e(t.timeItem).removeClass("selected first last");
            for (hour in n) e(t.timeItem + "-" + hour).addClass(n[hour])
        }, s.updateScrollPosition = function () {
            var n = e(t.timeItem + ".first").index() - 4,
                a = e(t.timeItem).width();
            e(t.scroller).scrollLeft(n * a)
        }, s
    }(jQuery), window.XC_VM = window.XC_VM || {}, window.XC_VM.Listings = window.XC_VM.Listings || {}, window.XC_VM.Listings.Nav = function (e) {
        var n = {
            daysForward: 6,
            daysBack: -6,
            requireHalfHour: !0,
            animationSpeed: 300,
            activeClass: "active",
            dayNavItem: ".js-day-nav-item",
            dayNavInner: ".js-listings-day-nav-inner",
            dayNavLink: ".js-day-nav-link",
            channelInfoBox: ".js-listings-channel-info",
            jumpItem: ".js-jump-item",
            nowBtn: ".js-now-btn"
        },
            t = e(window),
            a = e(".js-listings-day-slider"),
            i = e(".js-day-nav-arrow"),
            s = e(".js-time-nav-bar"),
            r = e(".js-times-slider"),
            o = e(".js-time-nav-arrow"),
            l = e(".js-listings-wrapper"),
            u = e(".js-listings-to-top-btn"),
            c = e(".js-listings-jump"),
            d = e(".js-toggle-jump"),
            h = e(".js-jump-overlay"),
            f = e(".js-listings-jump-dd"),
            p = function () {
                var t = I.getDays();
                i.show(), a.empty().append(Handlebars.templates.listingsDayNav({
                    days: t
                }));
                var s = e(n.dayNavItem).length * e(n.dayNavItem).width();
                e(n.dayNavInner).width(s)
            },
            m = function () {
                e(n.dayNavLink).on("click", function (t) {
                    if (t.preventDefault(), !e(this).parent().hasClass(n.activeClass)) {
                        var a = e(this).data("diff") || 0,
                            i = XC_VM.Listings.Grid.getStartDateTime(),
                            s = moment().add(a, "d").hour(i.hour()).minute(i.minute());
                        XC_VM.Listings.Grid.refreshGrid(s)
                    }
                })
            },
            g = function () {
                i.on("click", function (t) {
                    t.preventDefault();
                    var i = "next" == e(this).data("direction") ? "+" : "-";
                    a.animate({
                        scrollLeft: "+=" + i + e(".js-day-nav-item").outerWidth() * (2 * XC_VM.Listings.Grid.getHours())
                    }, n.animationSpeed)
                })
            },
            v = function () {
                o.on("click", function (n) {
                    n.preventDefault(), o.hide(), XC_VM.Listings.Grid.updateGridTime(e(this).data("direction"))
                })
            },
            y = function () {
                t.scroll(function () {
                    w()
                }), w()
            },
            w = function () {
                var e = l.offset().top,
                    n = e + l.height(),
                    a = t.scrollTop(),
                    i = a > e && a < n;
                s.width(l.outerWidth()), s.toggleClass("fixed", i), a > n && S()
            },
            T = function () {
                e(n.nowBtn).on("click", function (e) {
                    e.preventDefault(), XC_VM.Listings.Grid.refreshGrid()
                })
            },
            b = function () {
                u.hide(), u.addClass("fixed"), setTimeout(function () {
                    var n = 1e3;
                    t.scroll(function () {
                        var t = e(this);
                        t.scrollTop() < n ? u.hide() : u.show()
                    }), u.on("click", function (n) {
                        n.preventDefault(), e("html, body").animate({
                            scrollTop: 0
                        }, 50)
                    })
                }, 2e3)
            },
            _ = function () {
                l.swipe({
                    swipeLeft: function () {
                        XC_VM.Listings.Grid.updateGridTime("next")
                    },
                    swipeRight: function () {
                        XC_VM.Listings.Grid.updateGridTime("prev")
                    },
                    threshold: 80,
                    maxTimeThreshold: 500
                }), s.swipe({
                    swipeLeft: function () {
                        XC_VM.Listings.Grid.updateGridTime("next")
                    },
                    swipeRight: function () {
                        XC_VM.Listings.Grid.updateGridTime("prev")
                    },
                    threshold: 80,
                    maxTimeThreshold: 500
                })
            },
            D = function () {
                var e = R(),
                    n = Handlebars.templates.jumpTimes({
                        times: e
                    });
                f.append(n), M(), C()
            },
            k = function () {
                c.on("click", function (t) {
                    var a = e(t.target);
                    if (a.hasClass("js-jump-item")) S(), I.jumpToTime(a);
                    else {
                        e(n.channelInfoBox).hide(), d.toggleClass("expanded"), c.toggleClass("expanded"), f.toggle().scrollTop(0);
                        var i = f.is(":visible") ? "Opened" : "Closed";
                        "Opened" === i ? h.show() : h.hide(), "object" == typeof dataLayer && dataLayer.push({
                            event: "event.programmatic",
                            eventCategory: "Listings",
                            eventAction: "Time jump menu",
                            eventLabel: i
                        })
                    }
                }), h.on("click", function () {
                    S()
                })
            },
            S = function () {
                h.hide(), f.hide(), d.removeClass("expanded"), c.removeClass("expanded"), "object" == typeof dataLayer && dataLayer.push({
                    event: "event.programmatic",
                    eventCategory: "Listings",
                    eventAction: "Time jump menu",
                    eventLabel: "Closed"
                })
            },
            C = function () {
                var e;
                t.resize(function () {
                    clearTimeout(e), e = setTimeout(function () {
                        M()
                    }, 200)
                })
            },
            M = function () {
                var e = t.height() - 50;
                f.height(e)
            },
            R = function () {
                for (var e, n, t = moment().hour(19).minute(0).second(0), a = [], i = 0; i < 24; i++) e = t.format("h:mmA"), n = !1, 0 == t.hour() ? (e = "MIDNIGHT", n = !0) : 12 == t.hour() && (e = "NOON", n = !0), a.push({
                    displayTime: e,
                    highlight: n,
                    hour: t.hour()
                }), t.add(1, "h");
                return a
            },
            I = {};
        return I.showHideBackToTop = function () {
            var e = 1e3;
            l.height() < e && u.hide()
        }, I.updateDayNavScrollPosition = function () {
            var t = e(n.dayNavItem).width(),
                i = e(n.dayNavItem + "." + n.activeClass).index() - 1;
            i < 0 && (i = 0), a.scrollLeft(i * t)
        }, I.getDays = function () {
            for (var e = [], t = n.daysBack; t <= n.daysForward; t++) {
                var a = moment().add(t, "d");
                t == n.daysBack ? I.minDay = moment(a).hours(0).minutes(0).seconds(0) : t == n.daysForward && (I.maxDay = moment(a).add(1, "d").hours(0).minutes(0).seconds(0));
                var i = a.format("D MMM");
                a.isSame(moment().subtract(1, "d"), "day") ? i = "Yesterday" : a.isSame(moment(), "day") ? i = "Today" : a.isSame(moment().add(1, "d"), "day") ? i = "Tomorrow" : a.isAfter(moment().add(1, "d"), "day") && a.isBefore(moment().add(7, "d"), "day") && (i = a.format("dddd")), e.push({
                    fullDate: a.format("DDMMYYYY"),
                    displayName: i,
                    diff: t,
                    activeClass: a.isSame(moment(), "day") ? n.activeClass : ""
                })
            }
            return e
        }, I.highlightDay = function (t) {
            e(".js-day-nav-item").removeClass(n.activeClass), e(".js-day-" + t.format("DDMMYYYY")).addClass(n.activeClass)
        }, I.buildTimeBar = function () {
            var e = I.getTimes(XC_VM.Listings.Grid.getHours(), XC_VM.Listings.Grid.getStartDateTime(), n.requireHalfHour);
            r.empty().append(Handlebars.templates.listingsTimeBar({
                times: e
            })), I.updateCurrentTime()
        }, I.getTimes = function (e, t, a, i) {
            var s;
            s = 1 == a ? 2 * e : e;
            for (var r = 100 / s, o = [], l = moment(t), u = i || moment(), c = 0; c < s; c++) o.push({
                time: l.format("h.mma"),
                hour: l.format("HH"),
                width: r,
                className: "time-" + l.format("DDMMYYHHmm"),
                activeClass: l.isSame(u, "h") ? n.activeClass : ""
            }), 1 == a ? l.add(30, "m") : l.add(60, "m");
            return o
        }, I.updateCurrentTime = function () {
            var n = moment();
            n.minutes() >= 30 ? n.minutes(30) : n.minutes(0), e(".listings-time").removeClass("current"), e(".listings-time.time-" + n.format("DDMMYYHHmm")).addClass("current")
        }, I.jumpToTime = function (e) {
            var n = e.data("hour");
            if (void 0 !== n) {
                var t = moment(XC_VM.Listings.Grid.getStartDateTime());
                t.hour(n), XC_VM.Listings.Grid.refreshGrid(t, !1)
            }
        }, I.init = function () {
            p(), I.highlightDay(XC_VM.Listings.Grid.getStartDateTime()), I.updateDayNavScrollPosition(), m(), g(), _(), I.buildTimeBar(), v(), y(), D(), k(), T(), b()
        }, I
    }(jQuery, window.XC_VM.utilities), window.XC_VM = window.XC_VM || {}, window.XC_VM.Listings = window.XC_VM.Listings || {}, window.XC_VM.Listings.Grid = function (e, n) {
        var t = {};
        t.channels = [];
        var a = "tv",
            i = "TvChannels",
            s = {
                channelSummary: ".js-listings-channel-summary",
                channelInfo: ".js-listings-channel-info",
                channelClose: ".js-listings-channel-close",
                maxRating: 5,
                startDateTime: null,
                apiBaseUrl: "listings",
                viewWidth: null,
                sideWidth: null,
                hours: 3,
                bpTiny: 320,
                bpMobile: 414,
                bpTablet: 768,
                viewCheckInterval: 1e3,
                timelineInterval: 6e4,
                firstAdLoad: !0,
                listingsData: {},
                groupSizes: [8, 18],
                urlDateTimeParam: "sd",
                urlDateTimeFormat: "DD-MM-YYYY HH:mm",
                programmeData: {},
                hasPromoChannel: !1,
                promoChannelPos: 0
            },
            rM = e(".listings-mobile-nav"),
            r = e(".js-listings-wrapper"),
            o = e(".js-listings-container"),
            l = e(".js-listings-timeline"),
            u = e(".js-time-nav-arrow"),
            c = e(".js-time-nav-bar"),
            d = e(".js-listings-loader"),
            h = function () {
                s.sideWidth = e(window).width() < s.bpTablet ? 65 : 80, s.viewWidth = o.width() - s.sideWidth
            },
            f = function () {
                for (var e = decodeURIComponent(window.location.search.substring(1)), n = e.split("&"), a = 0; a < n.length; a++) {
                    var i = n[a].split("=");
                    if (i[0] == s.urlDateTimeParam) {
                        var r = moment(i[1], s.urlDateTimeFormat);
                        if (r.isValid()) return r
                    }
                    if ("time" == i[0]) {
                        var o = i[1].split(":"),
                            l = o[0],
                            u = o[1];
                        return moment().set("hours", l).set("minutes", u)
                    }
                }
                return moment()
            },
            p = function (e) {
                s.startDateTime = e ? moment(e).startOf("hour") : moment()
            },
            m = function () {
                var e = s.viewWidth;
                e > s.bpTablet ? s.hours = 3 : e > s.bpMobile ? s.hours = 2 : s.hours = 1
            },
            g = function () {
                s.pxpm = ((s.viewWidth - rM.width()) / (60 * s.hours)).toFixed(2)
            },
            v = function () {
                for (; t.channels.length;) t.channels.pop();
                var e = XC_VM.Listings.CookieSettings.getUpdatedChannels(i).split(",");
                t.channels = e, y()
            },
            y = function () {
                "undefined" != typeof XC_VM.Listings.promotedChannelId && XC_VM.Listings.promotedChannelId != -1 ? (s.hasPromoChannel = !0, s.groupSizes[0] = 9, t.channels.splice(s.promoChannelPos, 0, XC_VM.Listings.promotedChannelId)) : (s.hasPromoChannel = !1, s.groupSizes[0] = 8)
            },
            w = function (e) {
                return s.apiBaseUrl + "?startdate=" + e.format("YYYY-MM-DD") + "%20" + e.format("HH:mm:ss") + "&hours=" + s.hours + "&channels=" + t.channels.join() + "&hideempty=" + window.XC_VM.Listings.HideEmpty + "&timezone=" + Intl.DateTimeFormat().resolvedOptions().timeZone
            },
            T = function (n) {
                if (C(n), s.listingsData[n.format("DDMMYYYYHHmm")]) _(s.listingsData[n.format("DDMMYYYYHHmm")]), D(n);
                else {
                    var t = w(n);
                    e.getJSON(t).done(function (e) {
                        b(n, e), _(e), D(n)
                    }).fail(function () {
                        console.log("*** Listings: fetchData failed ***")
                    })
                }
            },
            b = function (e, n) {
                for (var t = "tv" === a, i = XC_VM.Listings.CookieSettings.personalisation("TvChannels").platform, r = 0; r < n.Channels.length; r++) {
                    var o = n.Channels[r],
                        l = 0;
                    if (o.isTv = t, o.Image = o.Image.replace(/&amp;/g, "&"), i)
                        for (var u = 0; u < o.Packages.length; u++) {
                            var c = o.Packages[u];
                            if (c.Package.toLowerCase() == i.toLowerCase()) {
                                o.selectedEpgChannel = c.EpgChannel;
                                break
                            }
                        }
                    for (var d = 0; d < o.TvListings.length; d++) {
                        var h = o.TvListings[d];
                        h.showContent = true, h.isTiny = h.RelativeSize < 15, h.rating = R(h.FilmStarRating), h.offAir = false;
                        var f = {
                            title: h.Title,
                            channel: o.DisplayName,
                            archiveData: h.Archive,
                            channelId: o.Id,
                            startTime: h.StartTime,
                            endTime: h.EndTime,
                            start: h.Start,
                            end: h.End
                        };
                        h.hoverInfo = JSON.stringify(f);
                    }
                }
                s.listingsData[e.format("DDMMYYYYHHmm")] = n;
                $('.tooltip').each(function () {
                    new $(this).jBox('Tooltip', { theme: 'TooltipDark', position: { x: 'left', y: 'center' }, outside: 'x' });
                });
                $('.tooltip-top').each(function () {
                    new $(this).jBox('Tooltip', { theme: 'TooltipDark' });
                });
            },
            _ = function (n) {
                var t = e.extend(!0, {}, n);
                o.empty();
                var r = {
                    Legend: t.Legend,
                    Channels: t.Channels
                };
                o.append(Handlebars.templates.listingsGrid(r));
                P(!1), N(), x(), XC_VM.Listings.Nav.showHideBackToTop(), u.show()
            },
            D = function (e) {
                var n = moment(e).subtract(s.hours, "h"),
                    t = moment(e).add(s.hours, "h");
                s.listingsData[n.format("DDMMYYYYHHmm")] || k(n), s.listingsData[t.format("DDMMYYYYHHmm")] || k(t)
            },
            k = function (n) {
                var t = w(n);
                e.getJSON(t).done(function (e) {
                    b(n, e)
                }).fail(function () {
                    console.log("*** Listings: fetchData failed ***")
                })
            },
            C = function (n) {
                var t = moment().startOf("d"),
                    a = moment(n).startOf("d"),
                    i = a.diff(t, "d"),
                    s = t.day() > 3 || t.day() + i < 13;
                e(".provisional-warning").toggleClass("showing", !s), r.toggleClass("provisional", !s)
            },
            M = function (e, n) {
                return e && e.length >= n ? e.substring(0, n).replace(/&lt;/g, "<").replace(/&gt;/g, ">") : e
            },
            R = function (e) {
                var n = [];
                if (e)
                    for (var t = 1; t <= s.maxRating; t++) n.push({
                        fill: e >= t
                    });
                return n
            },
            I = function () {
                setInterval(function () {
                    if (s.viewWidth != o.width() - s.sideWidth) {
                        h(), c.width(r.outerWidth());
                        var e = s.hours;
                        m(), g(), x(), e != s.hours && (s.listingsData = {}, T(s.startDateTime), XC_VM.Listings.Nav.buildTimeBar())
                    }
                }, s.viewCheckInterval)
            },
            L = function () {
                setInterval(function () {
                    x(), XC_VM.Listings.Nav.updateCurrentTime()
                }, s.timelineInterval)
            },
            x = function () {
                var e = moment(),
                    n = e.diff(s.startDateTime, "m"),
                    a = n * s.pxpm;
                a < 0 ? (a = 0, l.hide()) : a >= s.viewWidth ? (a = s.viewWidth, l.show(), l.addClass("out-of-view")) : (l.show(), l.removeClass("out-of-view")), l.width(a)
            },
            j = function () {
                e(document).on("click", s.channelSummary, function () {
                    e(s.channelInfo).hide();
                    var n = e(this).parent().find(s.channelInfo);
                    n.css({
                        top: "-" + n.height() / 3 + "px"
                    }), n.show()
                }), e(document).on("click", s.channelClose, function () {
                    e(this).closest(s.channelInfo).hide()
                })
            },
            E = function () {
                if ("object" == typeof dataLayer) {
                    var e = location.pathname + "?nav";
                    dataLayer.push({
                        event: "event.virtualPageview",
                        virtualPageUrl: e
                    })
                }
            },
            Y = function (e) {
            },
            O = function () {
                window.addEventListener && window.addEventListener("popstate", function () {
                    t.refreshGrid(f(), !1)
                })
            },
            P = function (e) {
                r.toggleClass("loader", e), d.position().top + d.outerHeight() > r.position().top + r.outerHeight() - 200 && (e = !1), d.toggle(e)
            },
            N = function () {
                if (s.programmeData[s.startDateTime.format("YYYY/MM/DD")])
                    for (var n = s.programmeData[s.startDateTime.format("YYYY/MM/DD")], t = 0; t < n.length; t++) {
                        var a = n[t],
                            i = e(".prog-" + a.contentId + ", .ep-" + a.contentId);
                        i.addClass("promoted-programme");
                        var r = "transparent";
                        "" !== a.imageUrl && (r += ' url("' + a.imageUrl + "?quality=60&mode=crop&anchor=topleft&width=" + i.outerWidth() + "&height=" + i.height() + '&404=tv") center center'), i.css({
                            background: r
                        })
                    } else H(s.startDateTime.format("YYYY/MM/DD"))
            },
            H = function (n) {

            };
        return t.getBritishTime = function (e) {
            var n = e || moment().startOf("hour"), t = new Date(n.year(), n.month(), n.date(), n.hour(), n.minute());
            return n
        }, t.updateGridTime = function (e) {
            var n = moment(s.startDateTime);
            "next" == e ? n.add(s.hours, "h") : "prev" == e && n.subtract(s.hours, "h");
            var a = n.isBefore(XC_VM.Listings.Nav.minDay),
                i = n.isAfter(XC_VM.Listings.Nav.maxDay);
            return a || i ? void u.show() : (p(n), void t.refreshGrid(s.startDateTime))
        }, t.refreshGrid = function (e, n) {
            n && (v(), s.listingsData = {}), p(e), XC_VM.Listings.Nav.highlightDay(s.startDateTime), XC_VM.Listings.Nav.updateDayNavScrollPosition(), XC_VM.Listings.Nav.buildTimeBar(), XC_VM.Listings.TimeSelector.updateHighlighting(), XC_VM.Listings.TimeSelector.updateScrollPosition(), P(!0), Y(s.startDateTime), T(s.startDateTime), n || E()
        }, t.getStartDateTime = function () {
            return s.startDateTime
        }, t.getViewWidth = function () {
            return s.viewWidth
        }, t.getHours = function () {
            return s.hours
        }, t.init = function () {
            O(), p(f()), h(), m(), v(), j(), I(), g(), L(), T(s.startDateTime), XC_VM.Listings.TimeSelector.init()
        }, t
    }(jQuery, window.XC_VM.EnvConfigHelper), window.XC_VM = window.XC_VM || {}, window.XC_VM.Listings = window.XC_VM.Listings || {}, (jQuery), window.XC_VM = window.XC_VM || {}, window.XC_VM.Listings = window.XC_VM.Listings || {};

(jQuery, window.XC_VM.EnvConfigHelper);
! function () {
    XC_VM.Listings.Settings.init(), XC_VM.Listings.Grid.init(), XC_VM.Listings.Nav.init()
}(), window.XC_VM = window.XC_VM || {}, (jQuery, window.XC_VM.EnvConfigHelper), window.XC_VM = window.XC_VM || {}, (window.XC_VM.utilities);