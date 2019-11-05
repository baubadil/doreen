/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { drnFindByID } from './shared';

declare global {
    namespace wysihtml {
        interface CopyStyles {
            andTo(element: Element): wysihtml.CopyStyles;
        }
        namespace views {
            abstract class View {
                public parent: wysihtml.Editor;
                public element: Element;
                public config: any;
                constructor(parent: wysihtml.Editor, element: Element, config);
            }
            abstract class Textarea extends View {
                constructor(parent: wysihtml.Editor, textareaElement: Element, config);
            }
            abstract class Composer extends View {
                public readonly selection: any;
                public readonly commands: any;
                public readonly textarea: Textarea;
                public readonly focusStylesHost: Element;
                public readonly blurStylesHost: Element;
                public readonly disabledStylesHost: Element;
                public readonly editableArea: Element;
                constructor(parent: wysihtml.Editor, editableElement: Element, config);
                public style(): Composer;
            }
        }
        abstract class dom {
            public static copyStyles(properties: string[]): {
                from: (element: Element) => {
                    to: (element: Element) => CopyStyles
                }
            };
        }
        class Editor {
            public readonly editableElement: Element;
            public readonly composer: wysihtml.views.Composer;
            public readonly textarea: wysihtml.views.Textarea;
            constructor(editableElement: string | Element, config: any);
            public on(event: string, listener: (event) => void): void;
            public observe(event: string, listener: (event) => void): void;
            public off(event?: string, listener?: (event) => void): void;
            public getValue(): string;
            public setValue(value: string, fCleanHTML: boolean): void;
            public destroy(): void;
        }
    }
}

/**
 *  Wrapper to create a WYSIHTML object from an existing <TEXTAREA>, whose ID must be passed in with
 *  idEditor. It is also assumed that there is a matching toolbar with the ID "idEditor-toolbar".
 *
 *  This is best used with the HTMLChunk::addWYSIHTMLEditor() PHP back-end function.
 *
 *  If fExtended == false, this uses "simple" HTML stripping rules for comment formatting.
 *  Tables are still supported though.
 *
 *  If fExtended == true, this creates a full-blown editor.
 *
 *  @return DrnWysihtml
 */
export default class DrnWysihtml
{
    private oWysi: wysihtml.Editor;
    private jqToolbar: JQuery;
    private fReady: boolean;
    private oResizeObserver: MutationObserver;

    constructor(idEditor: string,
                fExtended: boolean)
    {
        let idToolbar = idEditor + "-toolbar";

        this.fReady = false;
        this.oWysi = new wysihtml.Editor(
            idEditor,
            {
                toolbar:        idToolbar,
                parserRules:    (fExtended) ? WYSI_EXTENDED_RULES : WYSI_SIMPLE_RULES,
                stylesheets:    [ g_rootpage + "/3rdparty/wysihtml/css/editor.css" ],
                handleTables:   true,
                useLineBreaks:  false,
            });
        g_globals['wysihtml-' + idEditor] = this.oWysi;

        this.oWysi.on('load', () => {
            this.fReady = true;
        });

        let bookmark;
        this.jqToolbar = drnFindByID(idToolbar);
        let fnButton = (ev: JQueryEventObject) => {
            if (bookmark)
                this.oWysi.composer.selection.setBookmark(bookmark);
            let jq = $(ev.target);
            // Each of the buttons has 'data-row' and 'data-column' attributes that
            // we can access with the data() function.
            this.oWysi.composer.commands.exec('createTable',
                                               { rows: parseInt(jq.data('row'), 10),
                                                 cols: parseInt(jq.data('column'), 10) })
        };
        this.jqToolbar.find('.drn-wysi-table-button').on('click', fnButton);

        this.oWysi.on('blur:composer', () => {
            bookmark = this.oWysi.composer.selection.getBookmark();
        });

        this.initResizable(drnFindByID(idEditor).parent());
    }

    private resizeTextArea(height: string)
    {
        $(this.oWysi.composer.textarea.element).css({
            height
        });
        wysihtml.dom
            .copyStyles([ 'height' ])
            .from(this.oWysi.textarea.element)
            .to(this.oWysi.composer.focusStylesHost)
            .andTo(this.oWysi.composer.blurStylesHost)
            .andTo(this.oWysi.composer.disabledStylesHost)
            .andTo(this.oWysi.composer.editableArea);
    }

    private initResizable(jqResizable: JQuery)
    {
        // Check if resizing is enabled and supported
        if (   jqResizable.is('.drn-resizable')
            && "MutationObserver" in window
            && (!("CSS" in window) || CSS.supports('resize', 'vertical')))
        {
            this.oResizeObserver = new MutationObserver(() => {
                this.resizeTextArea(jqResizable.height() + "px");
            });
            this.oResizeObserver.observe(jqResizable[0], {
                attributes: true,
                attributeFilter: [ 'height', 'style' ]
            });
        }
    }

    public getValue(): string
    {
        return this.oWysi.getValue();
    }

    public setValue(v: string,
                    fCleanHTML: boolean = true)
    {
        if (this.fReady) {
            this.oWysi.setValue(v, fCleanHTML);
        }
        else {
            this.oWysi.on('load', () => {
                this.oWysi.setValue(v, fCleanHTML);
            });
        }
    }

    public destroy()
    {
        this.fReady = false;
        this.oResizeObserver.disconnect();
        this.oWysi.destroy();
        this.jqToolbar.find('.drn-wysi-table-button').off('click');
        delete g_globals['wysihtml-' + this.oWysi.editableElement.id];
        this.oWysi = undefined;
        this.jqToolbar = undefined;
    }
}

/**
 *
 *  Parser rules to be used by WYSIHTML.
 */

const WYSI_SIMPLE_RULES = {
    tags: {
        strong: {},
        b:      {},
        i:      {},
        em:     {},
        br:     {},
        p:      {},
        div:    {},
        span:   {},
        ul:     {},
        ol:     {},
        li:     {},
        a:      {
            set_attributes: {
                target: "_blank",
                rel:    "nofollow"
            },
            check_attributes: {
                href:   "url" // important to avoid XSS
            }
        }
    }
};

const WYSI_EXTENDED_RULES =
{
    /**
     * CSS Class white-list
     * Following CSS classes won't be removed when parsed by the wysihtml5 HTML parser
     */
    "classes": {
        "wysiwyg-clear-both": 1,
        "wysiwyg-clear-left": 1,
        "wysiwyg-clear-right": 1,
        "wysiwyg-color-aqua": 1,
        "wysiwyg-color-black": 1,
        "wysiwyg-color-blue": 1,
        "wysiwyg-color-fuchsia": 1,
        "wysiwyg-color-gray": 1,
        "wysiwyg-color-green": 1,
        "wysiwyg-color-lime": 1,
        "wysiwyg-color-maroon": 1,
        "wysiwyg-color-navy": 1,
        "wysiwyg-color-olive": 1,
        "wysiwyg-color-purple": 1,
        "wysiwyg-color-red": 1,
        "wysiwyg-color-silver": 1,
        "wysiwyg-color-teal": 1,
        "wysiwyg-color-white": 1,
        "wysiwyg-color-yellow": 1,
        "wysiwyg-float-left": 1,
        "wysiwyg-float-right": 1,
        "wysiwyg-font-size-large": 1,
        "wysiwyg-font-size-larger": 1,
        "wysiwyg-font-size-medium": 1,
        "wysiwyg-font-size-small": 1,
        "wysiwyg-font-size-smaller": 1,
        "wysiwyg-font-size-x-large": 1,
        "wysiwyg-font-size-x-small": 1,
        "wysiwyg-font-size-xx-large": 1,
        "wysiwyg-font-size-xx-small": 1,
        "wysiwyg-text-align-center": 1,
        "wysiwyg-text-align-justify": 1,
        "wysiwyg-text-align-left": 1,
        "wysiwyg-text-align-right": 1
    },
    /**
     * Tag list
     *
     * The following options are available:
     *
     *    - add_class:        converts and deletes the given HTML4 attribute (align, clear, ...) via the given method to a css class
     *                        The following methods are implemented in wysihtml5.dom.parse:
     *                          - align_text:  converts align attribute values (right/left/center/justify) to their corresponding css class "wysiwyg-text-align-*")
     *                            <p align="center">foo</p> ... becomes ... <p class="wysiwyg-text-align-center">foo</p>
     *                          - clear_br:    converts clear attribute values left/right/all/both to their corresponding css class "wysiwyg-clear-*"
     *                            <br clear="all"> ... becomes ... <br class="wysiwyg-clear-both">
     *                          - align_img:    converts align attribute values (right/left) on <img> to their corresponding css class "wysiwyg-float-*"
     *
     *    - add_style:        converts and deletes the given HTML4 attribute (align) via the given method to a css style
     *                        The following methods are implemented in wysihtml5.dom.parse:
     *                          - align_text:  converts align attribute values (right/left/center) to their corresponding css style)
     *                            <p align="center">foo</p> ... becomes ... <p style="text-align:center">foo</p>
     *
     *    - remove:             removes the element and its content
     *
     *    - unwrap              removes element but leaves content
     *
     *    - rename_tag:         renames the element to the given tag
     *
     *    - set_class:          adds the given class to the element (note: make sure that the class is in the "classes" white list above)
     *
     *    - set_attributes:     sets/overrides the given attributes
     *
     *    - check_attributes:   checks the given HTML attribute via the given method
     *                            - url:            allows only valid urls (starting with http:// or https://)
     *                            - src:            allows something like "/foobar.jpg", "http://google.com", ...
     *                            - href:           allows something like "mailto:bert@foo.com", "http://google.com", "/foobar.jpg"
     *                            - alt:            strips unwanted characters. if the attribute is not set, then it gets set (to ensure valid and compatible HTML)
     *                            - numbers:        ensures that the attribute only contains numeric (integer) characters (no float values or units)
     *                            - dimension:      for with/height attributes where floating point numbrs and percentages are allowed
     *                            - any:            allows anything to pass
     */
    "tags": {
        "tr": {
            "add_class": {
                "align": "align_text"
            }
        },
        "strike": {
            "remove": 1
        },
        "form": {
            "rename_tag": "div"
        },
        "rt": {
            "rename_tag": "span"
        },
        "code": {},
        "acronym": {
            "rename_tag": "span"
        },
        "br": {
            "add_class": {
                "clear": "clear_br"
            }
        },
        "details": {
            "rename_tag": "div"
        },
        "h4": {
            "add_class": {
                "align": "align_text"
            }
        },
        "em": {},
        "title": {
            "remove": 1
        },
        "multicol": {
            "rename_tag": "div"
        },
        "figure": {
            "rename_tag": "div"
        },
        "xmp": {
            "rename_tag": "span"
        },
        "small": {
            "rename_tag": "span",
            "set_class": "wysiwyg-font-size-smaller"
        },
        "area": {
            "remove": 1
        },
        "time": {
            "rename_tag": "span"
        },
        "dir": {
            "rename_tag": "ul"
        },
        "bdi": {
            "rename_tag": "span"
        },
        "command": {
            "remove": 1
        },
        "ul": {},
        "progress": {
            "rename_tag": "span"
        },
        "dfn": {
            "rename_tag": "span"
        },
        "iframe": {
            "remove": 1
        },
        "figcaption": {
            "rename_tag": "div"
        },
        "a": {
            "check_attributes": {
                "target": "any",
                "href": "url" // if you compiled master manually then change this from 'url' to 'href'
            },
            "set_attributes": {
                "rel": "nofollow"
            }
        },
        "img": {
            "check_attributes": {
                "width": "dimension",
                "alt": "alt",
                "src": "url", // if you compiled master manually then change this from 'url' to 'src'
                "height": "dimension"
            },
            "add_class": {
                "align": "align_img"
            }
        },
        "rb": {
            "rename_tag": "span"
        },
        "footer": {
            "rename_tag": "div"
        },
        "noframes": {
            "remove": 1
        },
        "abbr": {
            "rename_tag": "span"
        },
        "u": {},
        "bgsound": {
            "remove": 1
        },
        "address": {
            "rename_tag": "div"
        },
        "basefont": {
            "remove": 1
        },
        "nav": {
            "rename_tag": "div"
        },
        "h1": {
            "add_class": {
                "align": "align_text"
            }
        },
        "head": {
            "remove": 1
        },
        "tbody": {
            "add_class": {
                "align": "align_text"
            }
        },
        "dd": {
            "rename_tag": "div"
        },
        "s": {
            "rename_tag": "span"
        },
        "li": {},
        "td": {
            "check_attributes": {
                "rowspan": "numbers",
                "colspan": "numbers"
            },
            "add_class": {
                "align": "align_text"
            }
        },
        "object": {
            "remove": 1
        },
        "div": {
            "add_class": {
                "align": "align_text"
            }
        },
        "option": {
            "rename_tag": "span"
        },
        "select": {
            "rename_tag": "span"
        },
        "i": {},
        "track": {
            "remove": 1
        },
        "wbr": {
            "remove": 1
        },
        "fieldset": {
            "rename_tag": "div"
        },
        "big": {
            "rename_tag": "span",
            "set_class": "wysiwyg-font-size-larger"
        },
        "button": {
            "rename_tag": "span"
        },
        "noscript": {
            "remove": 1
        },
        "svg": {
            "remove": 1
        },
        "input": {
            "remove": 1
        },
        "table": {},
        "keygen": {
            "remove": 1
        },
        "h5": {
            "add_class": {
                "align": "align_text"
            }
        },
        "meta": {
            "remove": 1
        },
        "map": {
            "rename_tag": "div"
        },
        "isindex": {
            "remove": 1
        },
        "mark": {
            "rename_tag": "span"
        },
        "caption": {
            "add_class": {
                "align": "align_text"
            }
        },
        "tfoot": {
            "add_class": {
                "align": "align_text"
            }
        },
        "base": {
            "remove": 1
        },
        "video": {
            "remove": 1
        },
        "strong": {},
        "canvas": {
            "remove": 1
        },
        "output": {
            "rename_tag": "span"
        },
        "marquee": {
            "rename_tag": "span"
        },
        "b": {},
        "q": {
            "check_attributes": {
                "cite": "url"
            }
        },
        "applet": {
            "remove": 1
        },
        "span": {},
        "rp": {
            "rename_tag": "span"
        },
        "spacer": {
            "remove": 1
        },
        "source": {
            "remove": 1
        },
        "aside": {
            "rename_tag": "div"
        },
        "frame": {
            "remove": 1
        },
        "section": {
            "rename_tag": "div"
        },
        "body": {
            "rename_tag": "div"
        },
        "ol": {},
        "nobr": {
            "rename_tag": "span"
        },
        "html": {
            "rename_tag": "div"
        },
        "summary": {
            "rename_tag": "span"
        },
        "var": {
            "rename_tag": "span"
        },
        "del": {
            "remove": 1
        },
        "blockquote": {
            "check_attributes": {
                "cite": "url"
            }
        },
        "style": {
            "remove": 1
        },
        "device": {
            "remove": 1
        },
        "meter": {
            "rename_tag": "span"
        },
        "h3": {
            "add_class": {
                "align": "align_text"
            }
        },
        "textarea": {
            "rename_tag": "span"
        },
        "embed": {
            "remove": 1
        },
        "hgroup": {
            "rename_tag": "div"
        },
        "font": {
            "rename_tag": "span",
            "add_class": {
                "size": "size_font"
            }
        },
        "tt": {
            "rename_tag": "span"
        },
        "noembed": {
            "remove": 1
        },
        "thead": {
            "add_class": {
                "align": "align_text"
            }
        },
        "blink": {
            "rename_tag": "span"
        },
        "plaintext": {
            "rename_tag": "span"
        },
        "xml": {
            "remove": 1
        },
        "h6": {
            "add_class": {
                "align": "align_text"
            }
        },
        "param": {
            "remove": 1
        },
        "th": {
            "check_attributes": {
                "rowspan": "numbers",
                "colspan": "numbers"
            },
            "add_class": {
                "align": "align_text"
            }
        },
        "legend": {
            "rename_tag": "span"
        },
        "hr": {},
        "label": {
            "rename_tag": "span"
        },
        "dl": {
            "rename_tag": "div"
        },
        "kbd": {
            "rename_tag": "span"
        },
        "listing": {
            "rename_tag": "div"
        },
        "dt": {
            "rename_tag": "span"
        },
        "nextid": {
            "remove": 1
        },
        "pre": {},
        "center": {
            "rename_tag": "div",
            "set_class": "wysiwyg-text-align-center"
        },
        "audio": {
            "remove": 1
        },
        "datalist": {
            "rename_tag": "span"
        },
        "samp": {
            "rename_tag": "span"
        },
        "col": {
            "remove": 1
        },
        "article": {
            "rename_tag": "div"
        },
        "cite": {},
        "link": {
            "remove": 1
        },
        "script": {
            "remove": 1
        },
        "bdo": {
            "rename_tag": "span"
        },
        "menu": {
            "rename_tag": "ul"
        },
        "colgroup": {
            "remove": 1
        },
        "ruby": {
            "rename_tag": "span"
        },
        "h2": {
            "add_class": {
                "align": "align_text"
            }
        },
        "ins": {
            "rename_tag": "span"
        },
        "p": {
            "add_class": {
                "align": "align_text"
            }
        },
        "sub": {},
        "comment": {
            "remove": 1
        },
        "frameset": {
            "remove": 1
        },
        "optgroup": {
            "rename_tag": "span"
        },
        "header": {
            "rename_tag": "div"
        },
        "sup": {}
    }
};
