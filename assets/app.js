(function () {
  "use strict";

  var root = document.getElementById("toast-root");

  function showToast(type, text) {
    if (!root || !text) {
      return;
    }
    var isOk = type === "ok";

    var toast = document.createElement("div");
    toast.className = "toast toast-" + (isOk ? "ok" : "bad");
    toast.setAttribute("role", isOk ? "status" : "alert");

    var message = document.createElement("p");
    message.className = "toast-text";
    message.textContent = text;

    var close = document.createElement("button");
    close.type = "button";
    close.className = "toast-close";
    close.setAttribute("aria-label", "Закрыть уведомление");
    close.textContent = "✕";

    toast.appendChild(message);
    toast.appendChild(close);
    root.appendChild(toast);

    var hideTimer = window.setTimeout(dismiss, 6000);

    function dismiss() {
      window.clearTimeout(hideTimer);
      if (!toast.parentNode) {
        return;
      }
      toast.classList.add("toast-leaving");
      toast.addEventListener(
        "animationend",
        function () {
          if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
          }
        },
        { once: true }
      );
      // Safety net if animations are disabled (e.g. reduced motion).
      window.setTimeout(function () {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
      }, 400);
    }

    close.addEventListener("click", dismiss);
    toast.addEventListener("mouseenter", function () {
      window.clearTimeout(hideTimer);
    });
    toast.addEventListener("mouseleave", function () {
      hideTimer = window.setTimeout(dismiss, 2000);
    });
  }

  window.showToast = showToast;

  // Flash messages rendered by the server are picked up here and shown as toasts.
  document.querySelectorAll(".flash-data[data-flash-text]").forEach(function (node) {
    showToast(node.getAttribute("data-flash-type") || "bad", node.getAttribute("data-flash-text") || "");
    node.parentNode.removeChild(node);
  });

  // Confirm destructive actions before submitting.
  document.querySelectorAll("[data-confirm]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      var text = form.getAttribute("data-confirm");
      if (text && !window.confirm(text)) {
        event.preventDefault();
      }
    });
  });

  // Give forms a brief loading state so double-clicks don't fire the action twice
  // and the user gets instant feedback that something is happening.
  document.querySelectorAll("form").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      if (event.defaultPrevented) {
        return;
      }
      var button = form.querySelector('button[type="submit"]');
      if (!button || button.disabled) {
        return;
      }
      button.disabled = true;
      button.classList.add("is-loading");
      button.dataset.originalText = button.textContent || "";
      button.textContent = "Подождите…";
    });
  });

  // Copy-to-clipboard buttons with a toast confirmation.
  document.querySelectorAll("[data-copy]").forEach(function (button) {
    button.addEventListener("click", function () {
      var input = document.getElementById(button.getAttribute("data-copy") || "");
      if (!input) {
        return;
      }
      var original = button.textContent;
      navigator.clipboard
        .writeText(input.value)
        .then(function () {
          button.textContent = "Скопировано";
          showToast("ok", "Скопировано в буфер обмена.");
        })
        .catch(function () {
          input.focus();
          input.select();
          button.textContent = "Выделите и скопируйте";
        });
      window.setTimeout(function () {
        button.textContent = original;
      }, 1600);
    });
  });
})();
