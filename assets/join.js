(function () {
  "use strict";

  var body = document.body;
  if (!body || !body.classList.contains("join-page")) {
    return;
  }

  var needParams = body.getAttribute("data-need-params") === "1";
  var statusNode = document.getElementById("join-status");

  function setStatus(text) {
    if (statusNode && text) {
      statusNode.textContent = text;
    }
  }

  function loadBridge(done) {
    if (window.vkBridge && typeof window.vkBridge.send === "function") {
      done(window.vkBridge);
      return;
    }
    var script = document.createElement("script");
    script.src = "https://unpkg.com/@vkontakte/vk-bridge/dist/browser.min.js";
    script.async = true;
    script.onload = function () {
      if (window.vkBridge && typeof window.vkBridge.send === "function") {
        done(window.vkBridge);
        return;
      }
      setStatus("Не удалось подключить VK Bridge. Откройте ссылку внутри ВК.");
    };
    script.onerror = function () {
      setStatus("Не удалось загрузить VK Bridge. Проверьте интернет и откройте ссылку внутри ВК.");
    };
    document.head.appendChild(script);
  }

  function paramsFromSearch() {
    return window.location.search.indexOf("sign=") !== -1;
  }

  function toQuery(data) {
    var parts = [];
    Object.keys(data).forEach(function (key) {
      var value = data[key];
      if (value === undefined || value === null) {
        return;
      }
      parts.push(encodeURIComponent(key) + "=" + encodeURIComponent(String(value)));
    });
    return parts.join("&");
  }

  loadBridge(function (bridge) {
    bridge
      .send("VKWebAppInit")
      .then(function () {
        if (!needParams || paramsFromSearch()) {
          return null;
        }
        setStatus("Получаем данные запуска…");
        return bridge.send("VKWebAppGetLaunchParams");
      })
      .then(function (data) {
        if (!data || typeof data !== "object") {
          return;
        }
        if (!data.sign) {
          setStatus("Нет подписи запуска. Откройте ссылку из записи сообщества внутри ВК.");
          return;
        }
        var query = toQuery(data);
        var next = window.location.pathname + (query ? "?" + query : "");
        window.location.replace(next);
      })
      .catch(function () {
        if (needParams && !paramsFromSearch()) {
          setStatus("Не удалось получить параметры запуска. Откройте ссылку из записи внутри ВК.");
        }
      });
  });
})();
