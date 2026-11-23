(function () {
  "use strict";

  var body = document.body;
  if (!body || !body.classList.contains("join-page")) {
    return;
  }

  var needParams = body.getAttribute("data-need-params") === "1";
  var statusNode = document.getElementById("join-status");

  document.querySelectorAll("[data-copy]").forEach(function (button) {
    button.addEventListener("click", function () {
      var input = document.getElementById(button.getAttribute("data-copy") || "");
      if (!input) {
        return;
      }
      var original = button.textContent;
      var failed = function () {
        input.focus();
        input.select();
        button.textContent = "Выделите и скопируйте";
      };
      try {
        if (!navigator.clipboard || typeof navigator.clipboard.writeText !== "function") {
          failed();
        } else {
          navigator.clipboard
            .writeText(input.value)
            .then(function () {
              button.textContent = "Скопировано";
            })
            .catch(failed);
        }
      } catch (e) {
        failed();
      }
      window.setTimeout(function () {
        button.textContent = original;
      }, 1600);
    });
  });

  document.querySelectorAll("[data-share]").forEach(function (button) {
    button.addEventListener("click", function () {
      var input = document.getElementById(button.getAttribute("data-share") || "");
      if (!input || !input.value) {
        return;
      }
      var original = button.textContent;
      loadBridge(function (bridge) {
        bridge
          .send("VKWebAppShare", { link: input.value })
          .then(function () {
            button.textContent = "Открыто";
          })
          .catch(function () {
            input.focus();
            input.select();
            button.textContent = "Скопируйте ссылку";
          });
      });
      window.setTimeout(function () {
        button.textContent = original;
      }, 1600);
    });
  });

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
    var sources = [
      "assets/vk-bridge.min.js",
      "https://cdn.jsdelivr.net/npm/@vkontakte/vk-bridge@2.15.6/dist/browser.min.js"
    ];
    function loadAt(index) {
      if (window.vkBridge && typeof window.vkBridge.send === "function") {
        done(window.vkBridge);
        return;
      }
      if (index >= sources.length) {
        setStatus("Не удалось подключить VK Bridge. Откройте ссылку внутри ВК.");
        return;
      }
      var script = document.createElement("script");
      script.src = sources[index];
      script.async = true;
      script.onload = function () {
        if (window.vkBridge && typeof window.vkBridge.send === "function") {
          done(window.vkBridge);
          return;
        }
        loadAt(index + 1);
      };
      script.onerror = function () {
        loadAt(index + 1);
      };
      document.head.appendChild(script);
    }
    loadAt(0);
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

  function validRef(value) {
    return /^[1-9]\d*$/.test(value) ? value : "";
  }

  function refFromBlob(raw) {
    if (!raw) {
      return "";
    }
    var text = String(raw);
    if (text.charAt(0) === "?" || text.charAt(0) === "#") {
      text = text.slice(1);
    }
    var whole = validRef(text);
    if (whole) {
      return whole;
    }
    var parts = text.split("&");
    var j;
    for (j = 0; j < parts.length; j++) {
      var pair = parts[j].split("=");
      var key = "";
      var value = "";
      try {
        key = decodeURIComponent(pair[0] || "");
        value = decodeURIComponent(pair.slice(1).join("=") || "");
      } catch (e) {
        continue;
      }
      if (key === "hash") {
        var nested = refFromBlob(value);
        if (nested) {
          return nested;
        }
      }
      if (key === "ref" || key === "vk_ref") {
        var found = validRef(value);
        if (found) {
          return found;
        }
      }
    }
    return "";
  }

  function refParam() {
    return refFromBlob(window.location.search) || refFromBlob(window.location.hash);
  }

  function appendRef(query, ref) {
    if (!ref || refFromBlob(query)) {
      return query;
    }
    return query ? query + "&ref=" + encodeURIComponent(ref) : "ref=" + encodeURIComponent(ref);
  }

  var earlyRef = refParam();
  if (earlyRef && !refFromBlob(window.location.search)) {
    var glue = window.location.search ? "&" : "?";
    window.location.replace(
      window.location.pathname + window.location.search + glue + "ref=" + encodeURIComponent(earlyRef)
    );
    return;
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
        var ref = refParam();
        if (!ref && data.hash) {
          ref = refFromBlob(String(data.hash));
        }
        if (!ref && data.vk_ref) {
          ref = validRef(String(data.vk_ref));
        }
        query = appendRef(query, ref);
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
