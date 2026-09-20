/*!
 * jQuery Cookie Plugin v1.4.1
 * https://github.com/carhartl/jquery-cookie
 *
 * Copyright 2013 Klaus Hartl
 * Released under the MIT license
 */
(function (factory) {
	if (typeof define === 'function' && define.amd) {
		// AMD
		define(['jquery'], factory);
	} else if (typeof exports === 'object') {
		// CommonJS
		factory(require('jquery'));
	} else {
		// Browser globals
		factory(jQuery);
	}
}(function ($) {

	var pluses = /\+/g;

	function encode(s) {
		return config.raw ? s : encodeURIComponent(s);
	}

	function decode(s) {
		return config.raw ? s : decodeURIComponent(s);
	}

	function stringifyCookieValue(value) {
		return encode(config.json ? JSON.stringify(value) : String(value));
	}

	function parseCookieValue(s) {
		if (s.indexOf('"') === 0) {
			// This is a quoted cookie as according to RFC2068, unescape...
			s = s.slice(1, -1).replace(/\\"/g, '"').replace(/\\\\/g, '\\');
		}

		try {
			// Replace server-side written pluses with spaces.
			// If we can't decode the cookie, ignore it, it's unusable.
			// If we can't parse the cookie, ignore it, it's unusable.
			s = decodeURIComponent(s.replace(pluses, ' '));
			return config.json ? JSON.parse(s) : s;
		} catch(e) {}
	}

	function read(s, converter) {
		var value = config.raw ? s : parseCookieValue(s);
		return $.isFunction(converter) ? converter(value) : value;
	}

	var config = $.cookie = function (key, value, options) {

		// Write

		if (value !== undefined && !$.isFunction(value)) {
			options = $.extend({}, config.defaults, options);

			if (typeof options.expires === 'number') {
				var days = options.expires, t = options.expires = new Date();
				t.setTime(+t + days * 864e+5);
			}

			return (document.cookie = [
				encode(key), '=', stringifyCookieValue(value),
				options.expires ? '; expires=' + options.expires.toUTCString() : '', // use expires attribute, max-age is not supported by IE
				options.path    ? '; path=' + options.path : '',
				options.domain  ? '; domain=' + options.domain : '',
				options.secure  ? '; secure' : ''
			].join(''));
		}

		// Read

		var result = key ? undefined : Object.create(null);

		// To prevent the for loop in the first place assign an empty array
		// in case there are no cookies at all. Also prevents odd result when
		// calling $.cookie().
		var cookies = document.cookie ? document.cookie.split('; ') : [];

		for (var i = 0, l = cookies.length; i < l; i++) {
			var parts = cookies[i].split('=');
			var name = decode(parts.shift());
			var cookie = parts.join('=');

			if (key && key === name) {
				// If second argument (value) is a function it's a converter...
				result = read(cookie, value);
				break;
			}

			// Prevent storing a cookie that we couldn't decode.
			if (!key && (cookie = read(cookie)) !== undefined) {
				result[name] = cookie;
			}
		}

		return result;
	};

	config.defaults = {};

	$.removeCookie = function (key, options) {
		if (config(key) === undefined) {
			return false;
		}

		// Must not alter options, thus extending a fresh object...
		config(key, '', $.extend({}, options, { expires: -1 }));
		return !config(key);
	};

	// Kadupul LTS: preserve the pre-1.4 named-cookie API used by themes/plugins.
	// The upstream implementation remains responsible for cookie parsing/writing.
	$.cookie = function (key, value, options) {
		if (!arguments.length) {
			return config();
		}
		var writing = arguments.length > 1 && String(value) !== '[object Object]';
		var opts = writing ? $.extend({}, options) : (value || {});
		var previousRaw = config.raw;
		try {
			config.raw = opts.raw === undefined ? previousRaw : !!opts.raw;
			if (writing) {
				if (value === null || value === undefined) {
					opts.expires = -1;
					value = '';
				}
				// The old API counts calendar days, including DST transitions.
				if (typeof opts.expires === 'number') {
					var date = new Date();
					date.setDate(date.getDate() + opts.expires);
					opts.expires = date;
				}
				return config(key, String(value), opts);
			}
			var result = config(key);
			return result === undefined ? null : result;
		} finally {
			config.raw = previousRaw;
		}
	};
	['raw', 'json', 'defaults'].forEach(function (name) {
		Object.defineProperty($.cookie, name, {
			get: function () { return config[name]; },
			set: function (value) { config[name] = value; }
		});
	});

}));
