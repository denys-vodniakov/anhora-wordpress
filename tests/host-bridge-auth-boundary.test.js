const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
  path.join(__dirname, '..', 'anhora', 'assets', 'js', 'host-bridge.js'),
  'utf8'
);

function createStorage(values) {
  return {
    getItem(key) {
      return values.has(key) ? values.get(key) : null;
    },
    setItem(key, value) {
      values.set(key, String(value));
    },
  };
}

function bootBridge(user, storedValues) {
  const listeners = new Map();
  const events = [];
  class CustomEvent {
    constructor(type, options = {}) {
      this.type = type;
      this.detail = options.detail;
    }
  }
  const document = {
    readyState: 'complete',
    body: { dispatchEvent() {} },
    addEventListener(name, listener) {
      const current = listeners.get(name) || [];
      current.push(listener);
      listeners.set(name, current);
    },
    dispatchEvent(event) {
      events.push(event);
      for (const listener of listeners.get(event.type) || []) {
        listener(event);
      }
    },
  };
  const window = {
    document,
    sessionStorage: createStorage(storedValues),
    anhoraEmbed: { deploymentKey: 'deployment-test' },
    __ANHORA_HOST_BOOT__: {
      page: { url: 'https://store.example.test' },
      ...(user ? { user } : {}),
    },
    location: { href: 'https://store.example.test' },
  };

  vm.runInNewContext(source, {
    window,
    document,
    CustomEvent,
    URLSearchParams,
    Promise,
    parseInt,
  });
  document.dispatchEvent(new CustomEvent('anhora:ready'));
  return events;
}

test('keeps the transcript for the same signed-in WooCommerce customer', () => {
  const storage = new Map();
  bootBridge({ id: 'customer-a' }, storage);
  const events = bootBridge({ id: 'customer-a' }, storage);

  assert.equal(events.filter((event) => event.type === 'anhora:logout').length, 0);
});

test('emits logout when the customer becomes a guest or switches accounts', () => {
  const logoutStorage = new Map();
  bootBridge({ id: 'customer-a' }, logoutStorage);
  const logoutEvents = bootBridge(undefined, logoutStorage);
  assert.equal(
    logoutEvents.filter((event) => event.type === 'anhora:logout').length,
    1
  );

  const switchStorage = new Map();
  bootBridge({ id: 'customer-a' }, switchStorage);
  const switchEvents = bootBridge({ id: 'customer-b' }, switchStorage);
  assert.equal(
    switchEvents.filter((event) => event.type === 'anhora:logout').length,
    1
  );
});
