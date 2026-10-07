/******/ (function() { // webpackBootstrap
/*!****************************!*\
  !*** ./js/src/frontend.js ***!
  \****************************/
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i.return) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
/* global gform, gforms_recaptcha_recaptcha_strings, grecaptcha */
(function (gform, grecaptcha, strings) {
  var initScore = function initScore() {
    var isInitialized = false;
    document.addEventListener('gform/post_render', function (event) {
      // Abort if already initialized.
      if (isInitialized) {
        return;
      }
      isInitialized = true;

      // Executing on AJAX validation.
      gform.utils.addAsyncFilter('gform/ajax/pre_ajax_validation', /*#__PURE__*/function () {
        var _ref = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee(data) {
          return _regenerator().w(function (_context) {
            while (1) switch (_context.n) {
              case 0:
                _context.n = 1;
                return execute(data.form);
              case 1:
                return _context.a(2, data);
            }
          }, _callee);
        }));
        return function (_x) {
          return _ref.apply(this, arguments);
        };
      }());

      // Executing recaptcha on form submission or Next button click.
      gform.utils.addAsyncFilter('gform/submission/pre_submission', /*#__PURE__*/function () {
        var _ref2 = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2(data) {
          var recaptchaRequired;
          return _regenerator().w(function (_context2) {
            while (1) switch (_context2.n) {
              case 0:
                recaptchaRequired = data.submissionType === gform.submission.SUBMISSION_TYPE_SUBMIT || data.submissionType === gform.submission.SUBMISSION_TYPE_NEXT;
                if (!(recaptchaRequired && !data.abort)) {
                  _context2.n = 1;
                  break;
                }
                _context2.n = 1;
                return execute(data.form);
              case 1:
                return _context2.a(2, data);
            }
          }, _callee2);
        }));
        return function (_x2) {
          return _ref2.apply(this, arguments);
        };
      }());
    });
    maybeDisableBadge();
  };
  var initCheckbox = function initCheckbox() {
    document.addEventListener('gform/post_render', function (event) {
      renderCheckboxes(event.detail.formId);
    });
    document.addEventListener('gfcf/conversational/navigate/next', function (event) {
      if (!event.detail.target.classList.contains('gfield--type-recaptcha_checkbox')) {
        return;
      }
      var container = event.detail.target.querySelector('.g-recaptcha');
      renderCheckbox(container);
    });
    gform.addAction('gform_post_conditional_logic_field_action', function (formId, action, targetId, defaultValues, isInit) {
      if (action !== 'show') {
        return;
      }
      var field = gform.utils.getNode(targetId, document, true);
      if (!field || !field.classList.contains('gfield--type-recaptcha_checkbox')) {
        return;
      }
      var container = field.querySelector('.g-recaptcha');
      renderCheckbox(container, isInit);
    });
    gform.utils.addAsyncFilter('gform/ajax/post_ajax_submission', function (data) {
      var _data$submissionResul;
      if (!((_data$submissionResul = data.submissionResult) !== null && _data$submissionResul !== void 0 && (_data$submissionResul = _data$submissionResul.data) !== null && _data$submissionResul !== void 0 && _data$submissionResul.recaptcha_checkbox_response)) {
        return data;
      }
      var input = gform.utils.getNode('input.gfield_recaptcha_response', data.form, true);
      if (input) {
        input.value = data.submissionResult.data.recaptcha_checkbox_response;
      }
      return data;
    });
  };

  /**
   * @function execute
   * @description Execute standard or enterprise reCAPTCHA depending on the connection type.
   *
   * @since 2.2.0
   *
   * @param {HTMLFormElement} form The form element.
   *
   * @returns {Promise<void>}
   */
  var execute = /*#__PURE__*/function () {
    var _ref3 = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee3(form) {
      return _regenerator().w(function (_context3) {
        while (1) switch (_context3.n) {
          case 0:
            if (form) {
              _context3.n = 1;
              break;
            }
            return _context3.a(2);
          case 1:
            if (!((strings === null || strings === void 0 ? void 0 : strings.key_type) === 'CHECKBOX')) {
              _context3.n = 2;
              break;
            }
            return _context3.a(2);
          case 2:
            if (!(strings.connection_type === 'enterprise')) {
              _context3.n = 4;
              break;
            }
            _context3.n = 3;
            return executeEnterpriseRecaptcha(form);
          case 3:
            _context3.n = 5;
            break;
          case 4:
            _context3.n = 5;
            return executeRecaptcha(form);
          case 5:
            return _context3.a(2);
        }
      }, _callee3);
    }));
    return function execute(_x3) {
      return _ref3.apply(this, arguments);
    };
  }();

  /**
   * @function executeRecaptcha
   * @description Execute standard reCAPTCHA, setting the token to the hidden input field.
   *
   * @since 2.2.0
   *
   * @param {HTMLFormElement} form The form element.
   *
   * @returns {Promise<void>}
   */
  var executeRecaptcha = /*#__PURE__*/function () {
    var _ref4 = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee4(form) {
      var dataInput, token;
      return _regenerator().w(function (_context4) {
        while (1) switch (_context4.n) {
          case 0:
            dataInput = form.querySelector('.ginput_recaptchav3 .gfield_recaptcha_response'); // If reCAPTCHA fields can't be found, or recaptcha response is already set, don't execute reCAPTCHA.
            if (!(!dataInput || dataInput.value.length)) {
              _context4.n = 1;
              break;
            }
            return _context4.a(2);
          case 1:
            _context4.n = 2;
            return grecaptcha.execute(strings.site_key, {
              action: 'submit'
            });
          case 2:
            token = _context4.v;
            if (token.length && typeof token === 'string') {
              dataInput.value = token;
            }
          case 3:
            return _context4.a(2);
        }
      }, _callee4);
    }));
    return function executeRecaptcha(_x4) {
      return _ref4.apply(this, arguments);
    };
  }();

  /**
   * @function executeEnterpriseRecaptcha
   * @description Execute enterprise reCAPTCHA, setting the token to the hidden input field.
   *
   * @since 2.2.0
   *
   * @param {HTMLFormElement} form The form element.
   *
   * @returns {Promise<void>}
   */
  var executeEnterpriseRecaptcha = /*#__PURE__*/function () {
    var _ref5 = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee5(form) {
      var dataInput, token, _t;
      return _regenerator().w(function (_context5) {
        while (1) switch (_context5.p = _context5.n) {
          case 0:
            dataInput = form.querySelector('.ginput_recaptchav3 .gfield_recaptcha_response'); // If reCAPTCHA fields can't be found, or recaptcha response is already set, don't execute reCAPTCHA.
            if (!(!dataInput || dataInput.value.length)) {
              _context5.n = 1;
              break;
            }
            return _context5.a(2);
          case 1:
            _context5.p = 1;
            _context5.n = 2;
            return grecaptcha.enterprise.execute(strings.site_key, {
              action: 'submit'
            });
          case 2:
            token = _context5.v;
            if (token.length && typeof token === 'string') {
              dataInput.value = token;
            }
            _context5.n = 4;
            break;
          case 3:
            _context5.p = 3;
            _t = _context5.v;
            return _context5.a(2);
          case 4:
            return _context5.a(2);
        }
      }, _callee5, null, [[1, 3]]);
    }));
    return function executeEnterpriseRecaptcha(_x5) {
      return _ref5.apply(this, arguments);
    };
  }();
  maybeDisableBadge = function maybeDisableBadge() {
    if (strings.connection_type === 'enterprise') {
      grecaptcha.enterprise.ready(function () {
        hideBadge();
      });
    } else {
      grecaptcha.ready(function () {
        hideBadge();
      });
    }
  };
  hideBadge = function hideBadge() {
    if (strings.disable_badge) {
      var badge = document.querySelector('.grecaptcha-badge');
      if (badge) {
        badge.style.visibility = 'hidden';
      }
    }
  };
  var renderCheckboxes = function renderCheckboxes() {
    var formId = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : 0;
    var prefix = formId ? "#gform_".concat(formId) : '.gform_wrapper';
    var containers = document.querySelectorAll("".concat(prefix, " .ginput_container_recaptcha_checkbox .g-recaptcha"));
    if (!containers) {
      return;
    }
    containers.forEach(renderCheckbox);
  };
  var renderCheckbox = function renderCheckbox(container) {
    var _grecaptcha$enterpris;
    var isLogicInit = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : false;
    if (!(grecaptcha !== null && grecaptcha !== void 0 && (_grecaptcha$enterpris = grecaptcha.enterprise) !== null && _grecaptcha$enterpris !== void 0 && _grecaptcha$enterpris.render) || !container || container.dataset.initialized === 'true' || container.dataset.widgetid || !isLogicInit && isContainerHiddenByParent(container)) {
      return;
    }

    // If the iframe is present on the container, something else has already rendered it.
    if (container.querySelector('iframe')) {
      container.dataset.initialized = 'true';
      return;
    }
    var widgetId = grecaptcha.enterprise.render(container);
    if (!Number.isInteger(widgetId)) {
      return;
    }
    container.dataset.initialized = 'true';
    container.dataset.widgetid = widgetId;
  };
  var isContainerHiddenByParent = function isContainerHiddenByParent(container) {
    // Walk up the DOM from the container to the document root.
    var node = container.parentElement;
    while (node) {
      try {
        var style = getComputedStyle(node);
        if ((style === null || style === void 0 ? void 0 : style.display) === 'none') {
          return true;
        }
      } catch (e) {
        // Some environments may throw for detached nodes; ignore and continue.
      }
      node = node.parentElement;
    }
    return false;
  };
  window.gform = gform;
  window.gform.recaptchaV3 = {
    execute: execute
  };

  // Google requires a simple global function name for the onload callback. It looks like they call using array syntax: i.e. window['gravityformsrecaptchaRenderCheckboxes']();
  window.gravityformsrecaptchaRenderCheckboxes = renderCheckboxes;
  if ((strings === null || strings === void 0 ? void 0 : strings.key_type) === 'CHECKBOX') {
    initCheckbox();
  } else {
    initScore();
  }
})(window.gform || {}, window.grecaptcha || {}, gforms_recaptcha_recaptcha_strings);
/******/ })()
;
//# sourceMappingURL=frontend.js.map