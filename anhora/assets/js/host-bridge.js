/**
 * Anhora Host Bridge boot + WooCommerce add-to-cart.
 *
 * Expects window.__ANHORA_HOST_BOOT__ = { page?, products?, user?, orders? }
 * Optional window.anhoraCart = { homeUrl, storeApiAddItem, storeApiNonce }
 */
(function () {
  var authBoundaryHandled = false;

  function emit(name, detail) {
    document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    var legacy = name.replace(/^anhora:/, '');
    if (legacy !== name) {
      document.dispatchEvent(new CustomEvent(legacy, { detail: detail }));
    }
  }

  function authMarkerKey() {
    var deploymentKey =
      window.anhoraEmbed && window.anhoraEmbed.deploymentKey
        ? String(window.anhoraEmbed.deploymentKey)
        : 'default';
    return 'anhora_host_principal_' + deploymentKey;
  }

  function authPrincipal(data) {
    return data.user && data.user.id
      ? 'authenticated:' + String(data.user.id)
      : 'guest';
  }

  function handleAuthBoundary(data) {
    if (authBoundaryHandled) {
      return;
    }
    authBoundaryHandled = true;

    var current = authPrincipal(data);
    try {
      var key = authMarkerKey();
      var previous = window.sessionStorage.getItem(key);
      if (previous && previous !== current) {
        emit('anhora:logout');
      }
      window.sessionStorage.setItem(key, current);
    } catch (_error) {
      // Private browsing can reject storage; runtime user events still protect SPAs.
    }
  }

  function emitCartResult(requestId, ok, error) {
    if (!requestId) {
      return;
    }
    var detail = { requestId: requestId, ok: !!ok };
    if (!ok && error) {
      detail.error = error;
    }
    document.dispatchEvent(
      new CustomEvent('anhora:addToCartResult', { detail: detail })
    );
  }

  function boot(event) {
    var data = window.__ANHORA_HOST_BOOT__ || {};
    if (event && event.type === 'anhora:ready') {
      handleAuthBoundary(data);
    }
    if (data.page || Object.prototype.hasOwnProperty.call(data, 'user')) {
      emit('anhora:updateContext', {
        page: data.page,
        user: data.user,
      });
    }
    emit('anhora:updateUser', data.user);
    if (data.products && data.products.length) {
      emit('anhora:updateCatalog', { products: data.products });
    }
    if (data.orders && data.orders.length) {
      emit('anhora:updateOrderHistory', data.orders);
    }
  }

  function cartConfig() {
    return window.anhoraCart || {};
  }

  function homeUrl() {
    return cartConfig().homeUrl || window.__ANHORA_ADD_TO_CART_BASE__ || '/';
  }

  function normalizeProductId(raw) {
    if (raw == null || raw === '') {
      return '';
    }
    var id = String(raw);
    var prefixed = id.match(/^(?:woocommerce|woo):(\d+)$/i);
    if (prefixed) {
      return prefixed[1];
    }
    return /^\d+$/.test(id) ? id : '';
  }

  function stockErrorFromBody(body) {
    var blob = '';
    if (!body) {
      return false;
    }
    if (typeof body === 'string') {
      blob = body;
    } else {
      blob = [body.code, body.error, body.message]
        .filter(Boolean)
        .join(' ');
    }
    return /out[_ ]of[_ ]stock|stock/i.test(blob);
  }

  function notifyCartUpdated(fragments, cartHash) {
    document.body.dispatchEvent(
      new CustomEvent('wc-blocks_added_to_cart', { bubbles: true })
    );
    document.body.dispatchEvent(
      new CustomEvent('added_to_cart', { bubbles: true })
    );
    if (!window.jQuery) {
      return;
    }
    var $ = window.jQuery;
    if (fragments) {
      $.each(fragments, function (selector, html) {
        $(selector).replaceWith(html);
      });
    }
    $(document.body).trigger('added_to_cart', [fragments || {}, cartHash || '', null]);
    $(document.body).trigger('wc_fragment_refresh');
  }

  function addViaStoreApi(id, qty) {
    var cfg = cartConfig();
    if (!cfg.storeApiAddItem || !window.fetch) {
      return Promise.resolve({ skipped: true });
    }
    return window
      .fetch(cfg.storeApiAddItem, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Nonce: cfg.storeApiNonce || '',
        },
        body: JSON.stringify({
          id: parseInt(id, 10),
          quantity: qty,
        }),
      })
      .then(function (response) {
        if (response.ok) {
          notifyCartUpdated();
          return { ok: true };
        }
        return response
          .json()
          .then(function (body) {
            return {
              ok: false,
              error: stockErrorFromBody(body) ? 'out_of_stock' : 'unavailable',
            };
          })
          .catch(function () {
            return { ok: false, error: 'unavailable' };
          });
      })
      .catch(function () {
        return { ok: false, error: 'unavailable' };
      });
  }

  function classicAjaxUrl() {
    if (window.wc_add_to_cart_params && window.wc_add_to_cart_params.wc_ajax_url) {
      return window.wc_add_to_cart_params.wc_ajax_url
        .toString()
        .replace('%%endpoint%%', 'add_to_cart');
    }
    var base = homeUrl();
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'wc-ajax=add_to_cart';
  }

  function addViaClassicAjax(id, qty, parentId) {
    var body = new URLSearchParams();
    body.set('product_id', parentId || id);
    body.set('quantity', String(qty));
    if (parentId && parentId !== id) {
      body.set('variation_id', id);
    }

    if (window.jQuery) {
      return new Promise(function (resolve) {
        window.jQuery
          .post(classicAjaxUrl(), {
            product_id: parentId || id,
            variation_id: parentId && parentId !== id ? id : 0,
            quantity: qty,
          })
          .done(function (response) {
            if (response && response.error) {
              resolve({
                ok: false,
                error: stockErrorFromBody(response) ? 'out_of_stock' : 'unavailable',
              });
              return;
            }
            notifyCartUpdated(
              response && response.fragments,
              response && response.cart_hash
            );
            resolve({ ok: true });
          })
          .fail(function () {
            resolve({ ok: false, error: 'unavailable' });
          });
      });
    }

    if (!window.fetch) {
      return Promise.resolve({ ok: false, error: 'unavailable' });
    }

    return window
      .fetch(classicAjaxUrl(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
      .then(function (response) {
        if (!response.ok) {
          return { ok: false, error: 'unavailable' };
        }
        notifyCartUpdated();
        return { ok: true };
      })
      .catch(function () {
        return { ok: false, error: 'unavailable' };
      });
  }

  function onAddToCart(event) {
    var detail = (event && event.detail) || {};
    var requestId = typeof detail.requestId === 'string' ? detail.requestId : '';
    var id = normalizeProductId(
      detail.variationId || detail.variantId || detail.id || detail.productId
    );
    var parentId = normalizeProductId(detail.productId || detail.parentId);
    var qty = parseInt(detail.quantity, 10) || 1;
    if (!id) {
      emitCartResult(requestId, false, 'invalid');
      return;
    }

    addViaStoreApi(id, qty).then(function (result) {
      if (result && result.ok) {
        emitCartResult(requestId, true);
        return;
      }
      if (result && result.error === 'out_of_stock') {
        emitCartResult(requestId, false, 'out_of_stock');
        return;
      }
      if (result && result.skipped) {
        return addViaClassicAjax(id, qty, parentId).then(function (classic) {
          if (classic && classic.ok) {
            emitCartResult(requestId, true);
            return;
          }
          emitCartResult(
            requestId,
            false,
            (classic && classic.error) || 'unavailable'
          );
        });
      }
      return addViaClassicAjax(id, qty, parentId).then(function (classic) {
        if (classic && classic.ok) {
          emitCartResult(requestId, true);
          return;
        }
        emitCartResult(
          requestId,
          false,
          (classic && classic.error) || (result && result.error) || 'unavailable'
        );
      });
    });
  }

  document.addEventListener('anhora:addToCart', onAddToCart);
  document.addEventListener('anhora:ready', boot);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
