// public/js/employee.js
import { BrowserQRCodeReader } from "https://esm.sh/@zxing/browser@latest";

const apiBase = "../php/api.php";
const SUCCESS_VIDEO_SRC = "../media/success.mp4";

let loggedIn = false;
let controls = null; // ZXing Controls (zum Stoppen)
const video = document.getElementById("video");
const toggleBtn = document.getElementById("toggleScan");
const scanResult = document.getElementById("scanResult");
const redeemForm = document.getElementById("redeemForm");
const voucherInput = document.getElementById("voucherCode");
const cancelBtn = document.getElementById("cancelBtn");
const newScanBtn = document.getElementById("newScanBtn");

let lastNoCodeHintAt = 0;
const NO_CODE_HINT_COOLDOWN_MS = 1500;

function showAlert(el, cls, msg) {
  el.classList.remove(
    "d-none",
    "alert-success",
    "alert-danger",
    "alert-info",
    "alert-warning"
  );
  el.classList.add(cls);
  el.innerHTML = msg;
}

async function getCsrf() {
  const res = await fetch(`${apiBase}?action=csrf`);
  const data = await res.json();
  document.getElementById("csrf").value = data.csrf;
}

function resetVideoEl() {
  try {
    video.pause();
  } catch {}
  video.removeAttribute("src");
  video.srcObject = null;
  video.load?.();
}

function resetToLoginView() {
  loggedIn = false;
  try {
    stopScanning();
  } catch {}
  document.getElementById("scannerArea").classList.add("d-none");
  document.getElementById("loginForm").classList.remove("d-none");
  cancelBtn?.classList.add("d-none");
  newScanBtn?.classList.add("d-none");
  if (toggleBtn) {
    toggleBtn.textContent = "Kamera starten";
    toggleBtn.disabled = false;
  }
  showAlert(scanResult, "d-none", "");
  redeemForm.classList.add("d-none");
  voucherInput.value = "";
  resetVideoEl();
}

function setupLogin() {
  const form = document.getElementById("loginForm");
  const msg = document.getElementById("loginMsg");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const csrf = document.getElementById("csrf").value;
    const pin = document.getElementById("pin").value;
    const res = await fetch(`${apiBase}?action=employee_login`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ csrf, pin }),
    });
    const data = await res.json();
    if (data.error) return showAlert(msg, "alert-danger", data.error);

    loggedIn = true;
    form.classList.add("d-none");
    showAlert(msg, "alert-success", "Login erfolgreich.");
    document.getElementById("scannerArea").classList.remove("d-none");

    // Kamera direkt starten
    try {
      await startScanning();
      toggleBtn && (toggleBtn.textContent = "Scannen beenden");
      cancelBtn?.classList.remove("d-none");
      showAlert(
        scanResult,
        "alert-info",
        "Scanner läuft – halte den QR-Code in den Kamerabereich."
      );
    } catch (e2) {
      showAlert(
        scanResult,
        "alert-danger",
        "Kamera/Scanner konnte nicht gestartet werden: " + e2.message
      );
      toggleBtn && (toggleBtn.textContent = "Kamera starten");
    }
  });
}

async function startScanning() {
  // Für Mobilgeräte
  video.setAttribute("playsinline", "");
  video.muted = true;

  // Falls vorher ein Erfolgs-Video lief: abbinden
  resetVideoEl();

  const reader = new BrowserQRCodeReader();
  controls = await reader.decodeFromVideoDevice(
    undefined,
    video,
    async (result, err) => {
      if (result) {
        const code = (
          result.getText ? result.getText() : String(result)
        ).trim();

        // Code anzeigen + Formular sichtbar
        voucherInput.value = code;
        redeemForm.classList.remove("d-none");

        // Validieren – Kamera **nur** stoppen, wenn gültig
        const csrf = document.getElementById("csrf").value;
        try {
          const res = await fetch(`${apiBase}?action=validate_voucher`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ csrf, code }),
          });
          const data = await res.json();

          if (data.valid) {
            // Erfolg: Kamera stoppen, Video abspielen, Buttons anpassen
            await stopScanning();
            showAlert(
              scanResult,
              "alert-success",
              `Gutschein gültig. Rabatt: ${data.discount_percent}%`
            );
            // Erfolgs-Video abspielen
            playSuccessVideo().catch(() => {});
            // Button für neuen Scan sichtbar machen
            newScanBtn?.classList.remove("d-none");
            toggleBtn && (toggleBtn.textContent = "Kamera starten");
          } else {
            // Ungültig: Info zeigen, aber **weiter scannen**
            showAlert(
              scanResult,
              "alert-danger",
              `Ungültig: ${data.reason || "Unbekannt"}. Bitte erneut versuchen.`
            );
          }
        } catch (e) {
          // Validierungsfehler: **weiter scannen**
          showAlert(
            scanResult,
            "alert-danger",
            "Validierung fehlgeschlagen: " + e.message
          );
        }
        return;
      }

      // Kein Code im Frame – gedrosselter Hinweis
      if (err && Date.now() - lastNoCodeHintAt > NO_CODE_HINT_COOLDOWN_MS) {
        lastNoCodeHintAt = Date.now();
        showAlert(
          scanResult,
          "alert-warning",
          "Kein QR-Code erkannt. Bitte ruhig halten und vollständig im Bild platzieren."
        );
      }
    }
  );
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
  if (stream && stream.getTracks) stream.getTracks().forEach((t) => t.stop());
  video.srcObject = null;
}

async function playSuccessVideo() {
  resetVideoEl();
  video.src = SUCCESS_VIDEO_SRC;
  video.muted = false;
  video.volume = 1.0;
  try {
    await video.play();
  } catch {
    err;
  }
  {
    console.warn("Video mit Ton konnte nicht automatisch starten:", err);
  }
}

function setupToggleButton() {
  if (!toggleBtn) return;
  toggleBtn.addEventListener("click", async () => {
    if (!loggedIn) return alert("Bitte zuerst einloggen.");
    toggleBtn.disabled = true;
    try {
      if (!controls) {
        showAlert(
          scanResult,
          "alert-info",
          "Scanner läuft – richte die Kamera auf den QR-Code."
        );
        await startScanning();
        toggleBtn.textContent = "Scannen beenden";
        newScanBtn?.classList.add("d-none"); // falls von vorher sichtbar
      } else {
        await stopScanning();
        toggleBtn.textContent = "Kamera starten";
        showAlert(scanResult, "alert-warning", "Scanner gestoppt.");
      }
    } catch (e) {
      await stopScanning();
      toggleBtn.textContent = "Kamera starten";
      showAlert(
        scanResult,
        "alert-danger",
        "Kamera/Scanner konnte nicht gestartet werden: " + e.message
      );
    } finally {
      toggleBtn.disabled = false;
    }
  });

  window.addEventListener("pagehide", stopScanning);
  window.addEventListener("beforeunload", stopScanning);
}

function setupRedeemForm() {
  redeemForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const code = voucherInput.value.trim();
    const csrf = document.getElementById("csrf").value;
    const pin = document.getElementById("pin").value;

    const res = await fetch(`${apiBase}?action=redeem_voucher`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ csrf, code, pin }),
    });
    const data = await res.json();
    if (data.error) {
      showAlert(scanResult, "alert-danger", data.error);
    } else {
      showAlert(
        scanResult,
        "alert-success",
        `Einlösung erfasst. Rabatt: ${data.discount_percent}%`
      );
    }
  });
}

function setupCancelButton() {
  if (!cancelBtn) return;
  cancelBtn.addEventListener("click", (e) => {
    e.preventDefault();
    resetToLoginView();
    const loginMsg = document.getElementById("loginMsg");
    showAlert(
      loginMsg,
      "alert-info",
      "Abgebrochen. Bitte erneut einloggen, um weiter zu scannen."
    );
  });
}

function setupNewScanButton() {
  if (!newScanBtn) return;
  newScanBtn.addEventListener("click", async (e) => {
    e.preventDefault();
    // UI für neuen Scan vorbereiten
    newScanBtn.classList.add("d-none");
    // Je nach Wunsch: Vorherigen Code stehen lassen oder leeren.
    voucherInput.value = "";
    redeemForm.classList.add("d-none");
    showAlert(
      scanResult,
      "alert-info",
      "Neuer Scan – richte die Kamera auf den QR-Code."
    );
    try {
      await startScanning();
      toggleBtn && (toggleBtn.textContent = "Scannen beenden");
    } catch (err) {
      showAlert(
        scanResult,
        "alert-danger",
        "Kamera konnte nicht gestartet werden: " + err.message
      );
      toggleBtn && (toggleBtn.textContent = "Kamera starten");
    }
  });
}

// Kompakte Video-Höhe je nach verfügbarer Höhe dynamisch feintunen
(function tuneVideoHeight() {
  const root = document.documentElement;
  function setMax() {
    const h = window.visualViewport
      ? window.visualViewport.height
      : window.innerHeight;
    // Ziel: Video nicht mehr als ~40% der Höhe einnehmen
    const target =
      Math.max(30, Math.min(40, Math.round((h * 0.38) / (h / 100)))) + "dvh";
    root.style.setProperty("--videoMax", target);
  }
  setMax();
  window.addEventListener("resize", setMax);
})();

getCsrf().then(() => {
  setupLogin();
  setupToggleButton();
  setupRedeemForm();
  setupCancelButton();
  setupNewScanButton();
});
