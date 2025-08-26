const apiBase = "../php/api.php";

// --- UI helpers --------------------------------------------------------------
const $ = (s) => document.querySelector(s);
const alertBox = (el, type, html) => {
  if (!el) return;
  el.className = `alert mt-3 alert-${type}`;
  el.innerHTML = html;
  el.classList.remove("d-none");
};
const fetchJSON = async (url, opts) => {
  const res = await fetch(url, opts);
  if (!res.ok) throw new Error(`${res.status}`);
  return res.json();
};

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
  // CSRF & Ref setzen
  $("#csrf").value = (await fetchJSON(`${apiBase}?action=csrf`)).csrf;
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
      const data = await fetchJSON(`${apiBase}?action=register`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      if (data.error) {
        // Backend-Text wird angezeigt, aber die Stelle ist hier zentralisiert.
        alertBox($("#result"), "danger", data.error);
        return;
      }
      alertBox($("#result"), "success", M.registerOk(data.referral_url));
      form.reset();
      form.classList.remove("was-validated");
    } catch {
      alertBox($("#result"), "danger", M.registerErr);
    }
  });
})();
