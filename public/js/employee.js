// public/js/employee.js
import { BrowserQRCodeReader } from "https://esm.sh/@zxing/browser@latest";

const apiBase = "../php/api.php";

let loggedIn = false;
let controls = null; // ZXing Controls (zum Stoppen)
const video = document.getElementById("video");
const toggleBtn = document.getElementById("toggleScan");
const scanResult = document.getElementById("scanResult");
const redeemForm = document.getElementById("redeemForm");
const voucherInput = document.getElementById("voucherCode");

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
    showAlert(msg, "alert-success", "Login erfolgreich.");
    document.getElementById("scannerArea").classList.remove("d-none");
  });
}

async function startScanning() {
  // ZXing übernimmt getUserMedia selbst
  const reader = new BrowserQRCodeReader();
  controls = await reader.decodeFromVideoDevice(
    undefined,
    video,
    async (result, err) => {
      if (result) {
        const code = (
          result.getText ? result.getText() : String(result)
        ).trim();
        voucherInput.value = code;
        redeemForm.classList.remove("d-none");

        // Direkt validieren
        const csrf = document.getElementById("csrf").value;
        try {
          const res = await fetch(`${apiBase}?action=validate_voucher`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ csrf, code }),
          });
          const data = await res.json();
          if (data.valid) {
            showAlert(
              scanResult,
              "alert-success",
              `Gutschein gültig. Rabatt: ${data.discount_percent}%`
            );
          } else {
            showAlert(
              scanResult,
              "alert-danger",
              `Ungültig: ${data.reason || "Unbekannt"}`
            );
          }
        } catch (e) {
          showAlert(
            scanResult,
            "alert-danger",
            "Validierung fehlgeschlagen: " + e.message
          );
        }
      }
      // err: häufig nur "kein Code im aktuellen Frame" – nicht kritisch
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
  // Tracks werden von controls.stop() beendet; falls nicht:
  const stream = video.srcObject;
  if (stream && stream.getTracks) stream.getTracks().forEach((t) => t.stop());
  video.srcObject = null;
}

function setupToggleButton() {
  toggleBtn.addEventListener("click", async () => {
    if (!loggedIn) return alert("Bitte zuerst einloggen.");
    toggleBtn.disabled = true;
    try {
      if (!controls) {
        await startScanning();
        toggleBtn.textContent = "Scannen beenden";
        showAlert(
          scanResult,
          "alert-info",
          "Scanner läuft – richte die Kamera auf den QR-Code."
        );
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

  // Sicher stoppen, wenn Seite verlassen wird
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

getCsrf().then(() => {
  setupLogin();
  setupToggleButton();
  setupRedeemForm();
});
