/**
 * Anhora Host Bridge boot + cart listener (storefront).
 *
 * Expects window.__ANHORA_HOST_BOOT__ = {
 *   page?, products?, user?, orders?
 * }
 */
(function () {
  function emit(name, detail) {
    document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    // Legacy short names for older widget builds.
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

  function onAddToCart(event) {
    var detail = (event && event.detail) || {};
    var id = detail.id;
    var qty = detail.quantity || 1;
    if (!id) {
      return;
    }
    // Woo AJAX add-to-cart when available; otherwise navigate to add-to-cart URL.
    if (window.wc_add_to_cart_params && window.jQuery) {
      window.jQuery.post(window.wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart'), {
        product_id: id,
        quantity: qty,
      });
      return;
    }
    var url = (window.__ANHORA_ADD_TO_CART_BASE__ || '/') + '?add-to-cart=' + encodeURIComponent(id) + '&quantity=' + encodeURIComponent(qty);
    window.location.href = url;
  }

  document.addEventListener('anhora:addToCart', onAddToCart);
  document.addEventListener('addToCart', onAddToCart);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
