/**
 * Anhora Host Bridge boot + WooCommerce add-to-cart.
 *
 * Expects window.__ANHORA_HOST_BOOT__ = { page?, products?, user?, orders? }
 * Optional window.anhoraCart = { homeUrl, storeApiAddItem, storeApiNonce }
 */
(function () {
  function emit(name, detail) {
    document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    var legacy = name.replace(/^anhora:/, '');
    if (legacy !== name) {
      document.dispatchEvent(new CustomEvent(legacy, { detail: detail }));
    }
  }

  function boot() {
    var data = window.__ANHORA_HOST_BOOT__ || {};
    if (data.page || data.user) {
      emit('anhora:updateContext', {
        page: data.page,
        user: data.user,
      });
    }
    if (data.user) {
      emit('anhora:updateUser', data.user);
    }
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
      return Promise.resolve(false);
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
        if (!response.ok) {
          return false;
        }
        notifyCartUpdated();
        return true;
      })
      .catch(function () {
        return false;
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
              resolve(false);
              return;
            }
            notifyCartUpdated(
              response && response.fragments,
              response && response.cart_hash
            );
            resolve(true);
          })
          .fail(function () {
            resolve(false);
          });
      });
    }

    if (!window.fetch) {
      return Promise.resolve(false);
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
          return false;
        }
        notifyCartUpdated();
        return true;
      })
      .catch(function () {
        return false;
      });
  }

  function addViaRedirect(id, qty) {
    var base = homeUrl();
    var url =
      base +
      (base.indexOf('?') >= 0 ? '&' : '?') +
      'add-to-cart=' +
      encodeURIComponent(id) +
      '&quantity=' +
      encodeURIComponent(String(qty));
    window.location.href = url;
  }

  function onAddToCart(event) {
    var detail = (event && event.detail) || {};
    var id = normalizeProductId(
      detail.variationId || detail.variantId || detail.id || detail.productId
    );
    var parentId = normalizeProductId(detail.productId || detail.parentId);
    var qty = parseInt(detail.quantity, 10) || 1;
    if (!id) {
      return;
    }

    addViaStoreApi(id, qty).then(function (ok) {
      if (ok) {
        return;
      }
      return addViaClassicAjax(id, qty, parentId).then(function (classicOk) {
        if (!classicOk) {
          addViaRedirect(id, qty);
        }
      });
    });
  }

  document.addEventListener('anhora:addToCart', onAddToCart);
  document.addEventListener('addToCart', onAddToCart);
  document.addEventListener('anhora:ready', boot);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
