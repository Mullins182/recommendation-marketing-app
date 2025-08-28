const apiBase = "../php/api.php";

// --- UI helpers --------------------------------------------------------------
const $ = (s) => document.querySelector(s);
const alertBox = (el, type, html) => {
  if (!el) return;
  el.className = `alert mt-3 alert-${type}`;
  el.innerHTML = html;
  el.classList.remove("d-none");
};

async function fetchJSON(url, options = {}) {
  const res = await fetch(url, options);

  let data = {};
  try {
    data = await res.json();
  } catch {
    data = {};
  }

  // Immer Status + Daten zurückgeben
  return { status: res.status, data };
}

// --- Zentraler Textpool (Frontend-only) -------------------------------------
const M = {
  invalidEmail: "Bitte gib eine gültige E-Mail ein.",
  registering: "Registrierung läuft …",
  registerOk: (url) =>
    `Registrierung erfolgreich!<br>Dein Affiliate-Link: <a href="${url}">${url}</a><br>ist zusammen mit einem QR-Code des Links an Deine Emailadresse gesendet worden !`,
  registerErr: "Registrierung fehlgeschlagen. Bitte erneut versuchen.",
};

// --- Init --------------------------------------------------------------------
(async function init() {
  const { status: csrfStatus, data: csrfData } = await fetchJSON(
    `${apiBase}?action=csrf`,
    {
      method: "GET",
      credentials: "include", // Session-Cookie empfangen
    }
  );
  if (csrfStatus !== 200 || !csrfData?.csrf) {
    console.error("CSRF fetch failed", { csrfStatus, csrfData });
  } else {
    $("#csrf").value = csrfData.csrf;
  }
  const ref = new URLSearchParams(location.search).get("ref");
  if (ref) $("#ref").value = ref;

  // Formular
  const form = $("#registerForm");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!form.checkValidity()) {
      form.classList.add("was-validated");
      alertBox($("#result"), "danger", M.invalidEmail);
      return;
    }
    const payload = {
      email: $("#email").value.trim(),
      ref: $("#ref").value || null,
      csrf: $("#csrf").value,
    };

    alertBox($("#result"), "info", M.registering);
    try {
      const { status, data } = await fetchJSON(`${apiBase}?action=register`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
        credentials: "include", // Session-Cookie mitschicken
      });

      if (status === 409) {
        alertBox(
          $("#result"),
          "danger",
          data.msg || "Diese E-Mail-Adresse ist bereits registriert."
        );
        return;
      }

      if (status !== 200 || data.error) {
        alertBox($("#result"), "danger", data.error || M.registerErr);
        return;
      }

      alertBox($("#result"), "success", M.registerOk(data.referral_url));
      form.reset();
      form.classList.remove("was-validated");
    } catch (err) {
      alertBox($("#result"), "danger", M.registerErr);
    }
  });
})();
