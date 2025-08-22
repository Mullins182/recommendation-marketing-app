const apiBase = "../php/api.php";

async function getCsrf() {
  const res = await fetch(`${apiBase}?action=csrf`);
  const data = await res.json();
  document.getElementById("csrf").value = data.csrf;
}

function getRefFromUrl() {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get("ref");
}

function setUpForm() {
  const ref = getRefFromUrl();
  if (ref) document.getElementById("ref").value = ref;

  const form = document.getElementById("registerForm");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!form.checkValidity()) {
      form.classList.add("was-validated");
      return;
    }
    const email = document.getElementById("email").value.trim();
    const ref = document.getElementById("ref").value || null;
    const csrf = document.getElementById("csrf").value;

    const res = await fetch(`${apiBase}?action=register`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email, ref, csrf }),
    });
    const data = await res.json();
    const box = document.getElementById("result");
    box.classList.remove("d-none", "alert-danger", "alert-success");

    if (data.error) {
      box.classList.add("alert-danger");
      box.textContent = data.error;
    } else {
      box.classList.add("alert-success");
      box.innerHTML = `
        Registrierung erfolgreich!<br>
        Dein Empfehlungslink: <a href="${data.referral_url}">${data.referral_url}</a><br>
        Du erhältst gleich zusätzlich eine E-Mail mit deinem QR-Code.`;
      form.reset();
    }
  });
}

getCsrf().then(setUpForm);
