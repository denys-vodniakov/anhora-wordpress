/**
 * Injects the Anhora SaaS widget loader after the merchant configures a deployment key.
 * The remote script URL is passed from PHP via window.anhoraEmbed (documented in readme.txt).
 */
(function () {
  var config = window.anhoraEmbed || {};
  if (!config.loaderUrl || !config.deploymentKey) {
    return;
  }

  var script = document.createElement('script');
  script.src = config.loaderUrl;
  script.async = true;
  script.defer = true;
  script.setAttribute('data-anhora-deployment-key', config.deploymentKey);
  if (config.apiBase) {
    script.setAttribute('data-anhora-api-base', config.apiBase);
  }
  script.setAttribute('data-anhora-widget-channel', 'stable');
  document.head.appendChild(script);
})();
