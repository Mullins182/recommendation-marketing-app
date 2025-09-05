// public/js/employee.js
import { BrowserQRCodeReader } from "https://esm.sh/@zxing/browser@latest";

const apiBase = "../php/api.php";
const SUCCESS_VIDEO_SRC = "../media/success.mp4";
const FAILED_VIDEO_SRC = "../media/fail.mp4";

// --- Helpers -----------------------------------------------------------------
const $ = (s) => document.querySelector(s);
const on = (el, ev, fn) => el && el.addEventListener(ev, fn);
const alertBox = (el, type, html) => {
  if (!el) return;
  el.className = `alert alert-${type}`;
  el.innerHTML = html;
  el.classList.remove("d-none");
};
const hide = (el) => el && el.classList.add("d-none");

// JSON fetch, gibt bei Fehler die Backend-Message aus (statt nur Statuscode)
const fetchJSON = async (url, opts) => {
  const res = await fetch(url, opts);
  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    data = {};
  }
  if (!res.ok) {
    const msg = data?.error || data?.message || `${res.status}`;
    const err = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return data;
};

// --- Texte -------------------------------------------------------------------
const M = {
  loginOk: "Login erfolgreich.",
  pinWrong: "Pincode falsch !",
  mustLogin: "Bitte zuerst einloggen.",
  scanningStart: "Scanner läuft – richte die Kamera auf den QR-Code.",
  scanningStop: "Scanner gestoppt.",
  noCode:
    "Kein QR-Code erkannt. Bitte ruhig halten und vollständig im Bild platzieren.",
  validateErr: (m) => `Validierung fehlgeschlagen: ${m}`,
  success: (p) => `Gutschein gültig. Rabatt: ${p}%`,
  redeemed: (p) =>
    `Gutschein erfolgreich eingelöst.${p ? " Rabatt: " + p + "%" : ""}`,
  invalid: (reason = "Unbekannt") =>
    `Ungültig: ${reason}. Bitte erneut versuchen.`,
  camStartErr: (m) => `Kamera/Scanner konnte nicht gestartet werden: ${m}`,
  camStartFail: (m) => `Kamera konnte nicht gestartet werden: ${m}`,
  loggedOut: "Abgemeldet.",
};

// --- Elemente ----------------------------------------------------------------
let loggedIn = false;
let controls = null;

const video = $("#video");
const toggleBtn = $("#toggleScan");
const scanResult = $("#scanResult");
const redeemForm = $("#redeemForm");
const redeemBtn = $("#redeemBtn");
const voucherInput = $("#voucherCode");
const logoutBtn = $("#logoutBtn");
const loginMsg = $("#loginMsg");

// „Neuen QR-Scan starten“ & früherer Cancel-Button existieren nicht mehr
$("#newScanBtn")?.remove();

let lastNoCodeHintAt = 0;
const NO_CODE_HINT_MS = 1500;

// --- Bootstrap ---------------------------------------------------------------
(async function bootstrap() {
  $("#csrf").value = (await fetchJSON(`${apiBase}?action=csrf`)).csrf;
  setupLogin();
  setupToggle();
  setupRedeem();
  setupLogout();
  tuneVideoHeight();
})();

// --- Login -------------------------------------------------------------------
function setupLogin() {
  on($("#loginForm"), "submit", async (e) => {
    e.preventDefault();

    const body = JSON.stringify({
      csrf: $("#csrf").value,
      pin: $("#pin").value.trim(), // PIN nur hier senden
    });
    $("#pin").value = ""; // Eingabefeld leeren

    try {
      // WICHTIG: kein fetchJSON, damit wir Statuscode auf 403 mappen können
      const res = await fetch(`${apiBase}?action=employee_login`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body,
      });
      const text = await res.text();
      let data;
      try {
        data = text ? JSON.parse(text) : {};
      } catch {
        data = {};
      }

      if (!res.ok) {
        // (1) Falscher PIN: schöne, klare Meldung
        const msg =
          res.status === 403 ? M.pinWrong : data.error || `${res.status}`;
        return alertBox(loginMsg, "danger", msg);
      }

      loggedIn = true;
      $("#loginForm").classList.add("d-none");
      $("#scannerArea").classList.remove("d-none");
      logoutBtn.classList.remove("d-none");
      alertBox(loginMsg, "success", M.loginOk);

      try {
        await startScanning();
        toggleBtn.textContent = "Scannen beenden";
        alertBox(scanResult, "info", M.scanningStart);
      } catch (e2) {
        alertBox(scanResult, "danger", M.camStartErr(e2.message));
        toggleBtn.textContent = "QR-Scan starten";
      }
    } catch (e) {
      // Netzwerk-/andere Fehler → nüchtern anzeigen, KEIN Kamera-Text
      alertBox(loginMsg, "danger", e.message || "Unbekannter Fehler");
    }
  });
}

// --- Scanner -----------------------------------------------------------------
async function startScanning() {
  resetVideo();
  video.setAttribute("playsinline", "");
  video.muted = true;

  const reader = new BrowserQRCodeReader();
  controls = await reader.decodeFromVideoDevice(undefined, video, onFrame);
}

async function onFrame(result, err) {
  if (result) {
    const code = (result.getText ? result.getText() : String(result)).trim();
    voucherInput.value = code;

    // Kamera sofort stoppen, sobald ein Code erkannt wurde
    try {
      await stopScanning();
    } catch (_) {}
    toggleBtn.textContent = "QR-Scan starten";

    try {
      const data = await fetchJSON(`${apiBase}?action=validate_voucher`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ csrf: $("#csrf").value, code }),
      });

      if (data.valid) {
        alertBox(scanResult, "success", M.success(data.discount_percent));
        playSuccessVideo().catch(() => {});
        redeemForm.classList.remove("d-none");
      } else {
        playFailedVideo().catch(() => {});
        // Backend-Text direkt anzeigen, Fallback wenn leer
        const msg =
          typeof data.reason === "string" && data.reason.trim()
            ? data.reason.trim()
            : M.invalid();

        alertBox(scanResult, "danger", msg);
      }
    } catch (e) {
      alertBox(scanResult, "danger", M.validateErr(e.message));
    }
    return;
  }

  if (err && Date.now() - lastNoCodeHintAt > NO_CODE_HINT_MS && !controls) {
    lastNoCodeHintAt = Date.now();
    alertBox(scanResult, "warning", M.noCode);
  }
}

async function stopScanning() {
  try {
    controls?.stop();
  } catch {}
  controls = null;
  try {
    await video.pause();
  } catch {}
  const stream = video.srcObject;
  if (stream?.getTracks) stream.getTracks().forEach((t) => t.stop());
  video.srcObject = null;
}

function resetVideo() {
  try {
    video.pause();
  } catch {}
  video.removeAttribute("src");
  video.srcObject = null;
  video.load?.();
}

async function playSuccessVideo() {
  resetVideo();
  video.src = SUCCESS_VIDEO_SRC;
  video.muted = false;
  video.volume = 1;
  try {
    await video.play();
  } catch {}
}

async function playFailedVideo() {
  resetVideo();
  video.src = FAILED_VIDEO_SRC;
  video.muted = false;
  video.volume = 1;
  try {
    await video.play();
  } catch {}
}

// --- UI: Toggle Scan ---------------------------------------------------------
function setupToggle() {
  on(toggleBtn, "click", async () => {
    if (!loggedIn) return alert(M.mustLogin);
    toggleBtn.disabled = true;
    try {
      if (!controls) {
        alertBox(scanResult, "info", M.scanningStart);
        await startScanning();
        toggleBtn.textContent = "Scannen beenden";
      } else {
        await stopScanning();
        toggleBtn.textContent = "QR-Scan starten";
        alertBox(scanResult, "warning", M.scanningStop);
      }
    } catch (e) {
      await stopScanning();
      toggleBtn.textContent = "QR-Scan starten";
      alertBox(scanResult, "danger", M.camStartErr(e.message));
    } finally {
      toggleBtn.disabled = false;
    }
  });
  on(window, "pagehide", stopScanning);
  on(window, "beforeunload", stopScanning);
}

// --- Einlösen ----------------------------------------------------------------
function setupRedeem() {
  on(redeemForm, "submit", async (e) => {
    e.preventDefault();

    // Doppelklicks sofort verhindern
    if (redeemBtn.disabled) return;
    redeemBtn.disabled = true;

    try {
      if (!loggedIn) {
        alertBox(scanResult, "danger", M.mustLogin);
        return;
      }

      const body = JSON.stringify({
        csrf: $("#csrf").value,
        code: voucherInput.value.trim(),
      });

      const res = await fetch(`${apiBase}?action=redeem_voucher`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body,
        credentials: "same-origin",
      });

      const text = await res.text();
      let data;
      try {
        data = text ? JSON.parse(text) : {};
      } catch {
        data = {};
      }

      if (!res.ok) {
        alertBox(scanResult, "danger", data.error || `Fehler ${res.status}`);
        return;
      }

      // Erfolgreich eingelöst: Formular weg, Meldung zeigen
      redeemForm.classList.add("d-none");
      alertBox(scanResult, "success", M.redeemed(data.discount_percent));
    } catch (err) {
      alertBox(scanResult, "danger", M.validateErr(err.message));
    } finally {
      redeemBtn.disabled = false;
    }
  });
}

// --- Logout ------------------------------------------------------------------
function setupLogout() {
  on(logoutBtn, "click", async (e) => {
    e.preventDefault();
    try {
      await fetchJSON(`${apiBase}?action=employee_logout`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ csrf: $("#csrf").value }),
        credentials: "same-origin",
      });
    } catch {}
    loggedIn = false;
    await stopScanning();
    $("#scannerArea").classList.add("d-none");
    $("#loginForm").classList.remove("d-none");
    toggleBtn.textContent = "QR-Scan starten";
    logoutBtn.classList.add("d-none");
    hide(scanResult);
    redeemForm.classList.add("d-none");
    voucherInput.value = "";
    resetVideo();
    alertBox(loginMsg, "info", M.loggedOut);
  });
}

// --- Video-Höhe (mobil) ------------------------------------------------------
function tuneVideoHeight() {
  const root = document.documentElement;
  const setMax = () => {
    const h = window.visualViewport
      ? window.visualViewport.height
      : innerHeight;
    root.style.setProperty(
      "--videoMax",
      Math.max(30, Math.min(40, (h * 0.38) / (h / 100))) + "dvh"
    );
  };
  setMax();
  window.addEventListener("resize", setMax);
}
