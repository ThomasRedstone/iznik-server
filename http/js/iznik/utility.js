define([
    'jquery',
    'underscore'
], function($, _) {
    // TODO Namespace pollution here
    //
    // Ensure we can log.
    if (!window.console) {
        window.console = {};
    }
    if (!console.log) {
        console.log = function (str) {
        };
    }

    if (!console.error) {
        console.error = function (str) {
            window.alert(str);
        };
    }

    window.isNarrow = function() {
        return window.innerWidth < 749;
    };

    window.isShort = function() {
        return window.innerHeight < 900;
    };

    window.isVeryShort = function() {
        return window.innerHeight <= 300;
    };

    window.canonSubj = function(subj) {
        subj = subj.toLocaleLowerCase();

        // Remove any group tag
        subj = subj.replace(/^\[.*\](.*)/, "$1");

        // Remove duplicate spaces
        subj = subj.replace(/\s+/g, ' ');

        subj = subj.trim();

        return (subj);
    };

    window.setURLParam = function(uri, key, value) {
        console.log("Set url param", uri, key, value);
        var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
        var separator = uri.indexOf('?') !== -1 ? "&" : "?";
        if (uri.match(re)) {
            return uri.replace(re, '$1' + key + "=" + value + '$2');
        }
        else {
            return uri + separator + key + "=" + value;
        }
    };

    window.removeURLParam = function(uri, key) {
        var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
        if (uri.match(re)) {
            console.log("Found param");
            return uri.replace(re, '$1$2');
        }
        else {
            return uri;
        }
    };

    window.isNarrow = function() {
        return window.innerWidth < 749;
    };

    window.getDistanceFromLatLonInKm = function(lat1, lon1, lat2, lon2) {
        var R = 6371; // Radius of the earth in km
        var dLat = deg2rad(lat2 - lat1);  // deg2rad below
        var dLon = deg2rad(lon2 - lon1);
        var a =
                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(deg2rad(lat1)) * Math.cos(deg2rad(lat2)) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2)
            ;
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return (R * c);
    };

    window.deg2rad = function(deg) {
        return deg * (Math.PI / 180)
    };

    window.decodeEntities = (function () {
        // this prevents any overhead from creating the object each time
        var element = document.createElement('div');

        function decodeHTMLEntities(str) {
            if (str && typeof str === 'string') {
                // strip script/html tags
                str = str.replace(/<script[^>]*>([\S\s]*?)<\/script>/gmi, '');
                str = str.replace(/<\/?\w(?:[^"'>]|"[^"]*"|'[^']*')*>/gmi, '');
                element.innerHTML = str;
                str = element.textContent;
                element.textContent = '';
            }

            return str;
        }

        return decodeHTMLEntities;
    })();

    window.encodeHTMLEntities = function(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    // Apply a custom order to a set of messages
    window.orderedMessages = function(stdmsgs, order) {
        var sortmsgs = [];
        if (!_.isUndefined(order)) {
            order = JSON.parse(order);
            _.each(order, function (id) {
                var stdmsg = null;
                _.each(stdmsgs, function (thisone) {
                    if (thisone.id == id) {
                        stdmsg = thisone;
                    }
                });

                if (stdmsg) {
                    sortmsgs.push(stdmsg);
                    stdmsgs = _.without(stdmsgs, stdmsg);
                }
            });
        }

        sortmsgs = $.merge(sortmsgs, stdmsgs);
        return (sortmsgs);
    };

    /**
     * Class for creating csv strings
     * Handles multiple data types
     * Objects are cast to Strings
     **/

    window.csvWriter = function(del, enc) {
        this.del = del || ','; // CSV Delimiter
        this.enc = enc || '"'; // CSV Enclosure

        // Convert Object to CSV column
        this.escapeCol = function (col) {
            if (isNaN(col)) {
                // is not boolean or numeric
                if (!col) {
                    // is null or undefined
                    col = '';
                } else {
                    // is string or object
                    col = String(col);
                    if (col.length > 0) {
                        // use regex to test for del, enc, \r or \n
                        // if(new RegExp( '[' + this.del + this.enc + '\r\n]' ).test(col)) {

                        // escape inline enclosure
                        col = col.split(this.enc).join(this.enc + this.enc);

                        // wrap with enclosure
                        col = this.enc + col + this.enc;
                    }
                }
            }
            return col;
        };

        // Convert an Array of columns into an escaped CSV row
        this.arrayToRow = function (arr) {
            var arr2 = arr.slice(0);

            var i, ii = arr2.length;
            for (i = 0; i < ii; i++) {
                arr2[i] = this.escapeCol(arr2[i]);
            }
            return arr2.join(this.del);
        };

        // Convert a two-dimensional Array into an escaped multi-row CSV
        this.arrayToCSV = function (arr) {
            var arr2 = arr.slice(0);

            var i, ii = arr2.length;
            for (i = 0; i < ii; i++) {
                arr2[i] = this.arrayToRow(arr2[i]);
            }
            return arr2.join("\r\n");
        };
    };

    window.presdef = function (key, obj, def) {
        var ret = obj && obj.hasOwnProperty(key) ? obj[key] : def;
        return (ret);
    };

    window.chunkArray = function(array, size) {
        var start = array.byteOffset || 0;
        array = array.buffer || array;
        var index = 0;
        var result = [];
        while (index + size <= array.byteLength) {
            result.push(new Uint8Array(array, start + index, size));
            index += size;
        }
        if (index <= array.byteLength) {
            result.push(new Uint8Array(array, start + index));
        }
        return result;
    };

    window.base64url = {
        _strmap: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_',
        encode: function encode(data) {
            data = new Uint8Array(data);
            var len = Math.ceil(data.length * 4 / 3);
            return chunkArray(data, 3).map(function (chunk) {
                return [chunk[0] >>> 2, (chunk[0] & 0x3) << 4 | chunk[1] >>> 4, (chunk[1] & 0xf) << 2 | chunk[2] >>> 6, chunk[2] & 0x3f].map(function (v) {
                    return base64url._strmap[v];
                }).join('');
            }).join('').slice(0, len);
        },
        _lookup: function _lookup(s, i) {
            return base64url._strmap.indexOf(s.charAt(i));
        },
        decode: function decode(str) {
            var v = new Uint8Array(Math.floor(str.length * 3 / 4));
            var vi = 0;
            for (var si = 0; si < str.length;) {
                var w = base64url._lookup(str, si++);
                var x = base64url._lookup(str, si++);
                var y = base64url._lookup(str, si++);
                var z = base64url._lookup(str, si++);
                v[vi++] = w << 2 | x >>> 4;
                v[vi++] = x << 4 | y >>> 2;
                v[vi++] = y << 6 | z;
            }
            return v;
        }
    };

    window.isValidEmailAddress = function(emailAddress) {
        var pattern = /^([a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+(\.[a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+)*|"((([ \t]*\r\n)?[ \t]+)?([\x01-\x08\x0b\x0c\x0e-\x1f\x7f\x21\x23-\x5b\x5d-\x7e\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|\\[\x01-\x09\x0b\x0c\x0d-\x7f\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))*(([ \t]*\r\n)?[ \t]+)?")@(([a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])\.)+([a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])\.?$/i;
        return pattern.test(emailAddress);
    };

    window.wbr = function(str, num) {
        var re = RegExp("([^\\s]{" + num + "})(\\w)", "g");
        return str.replace(re, function(all,text,char){
            return text + "<wbr>" + char;
        });
    };
});