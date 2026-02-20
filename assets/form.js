(function () {
  function qs(sel, root = document) {
    return root.querySelector(sel);
  }

  function qsa(sel, root = document) {
    return Array.from(root.querySelectorAll(sel));
  }

  function setStatus(form, msg, ok) {
    const el = qs(".ws68502-status", form);
    if (!el) return;
    el.textContent = msg || "";
    el.dataset.ok = ok ? "1" : "0";
  }

  function setFieldError(form, name, msg) {
    const err = qs(`.ws68502-error[data-for="${CSS.escape(name)}"]`, form);
    if (err) err.textContent = msg || "";

    const input = qs(`[name="${CSS.escape(name)}"]`, form);
    if (input) {
      if (msg) input.setAttribute("aria-invalid", "true");
      else input.removeAttribute("aria-invalid");
    }
  }

  function clearAllErrors(form) {
    qsa(".ws68502-error", form).forEach((el) => (el.textContent = ""));
    qsa("[aria-invalid='true']", form).forEach((el) =>
      el.removeAttribute("aria-invalid")
    );
  }


  function validate(data) {
    const errors = {};

    // Required fields
const required = [
  "firstName",
  "lastName",
  "address1",
  "city",
  "state",
  "zip",
  "phone",
  "email",
  "birthdate",
];
    required.forEach((k) => {
      if (!data[k] || !String(data[k]).trim()) errors[k] = "Required";
    });

    // Email
    if (data.email) {
      const email = String(data.email).trim();
      // Simple, practical email check
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = "Invalid email";
    }

    // State (2 letters)
 

    // ZIP (5 or 9)
    if (data.zip) {
      const zip = String(data.zip).trim();
      if (!/^\d{5}(-\d{4})?$/.test(zip)) errors.zip = "Invalid ZIP";
    }

    // Birthdate basic sanity check
    if (data.birthdate) {
      const d = new Date(data.birthdate);
      if (Number.isNaN(d.getTime())) errors.birthdate = "Invalid date";
    }

    return errors;
  }

  function collectFormData(form) {
 const fields = [
  "firstName",
  "lastName",
  "address1",
  "address2",
  "city",
  "state",
  "zip",
  "phone",
  "email",
  "birthdate",
  "nonce",
];
    const data = {};
    fields.forEach((k) => {
      const el = qs(`[name="${CSS.escape(k)}"]`, form);
      data[k] = el ? el.value : "";
    });

 

    return data;
  }

  async function postLead(data) {
    const fd = new FormData();
    // WordPress AJAX requires an "action"
    fd.append("action", WS68502LeadForm.action);

    Object.keys(data).forEach((k) => fd.append(k, data[k] ?? ""));

    const res = await fetch(WS68502LeadForm.ajaxUrl, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
    });

    // WP can return non-JSON in edge cases; guard it
    const text = await res.text();
    let json;
    try {
      json = JSON.parse(text);
    } catch (e) {
      throw new Error("Server returned an unexpected response.");
    }

    if (!res.ok || !json || json.ok !== true) {
      const message = (json && json.message) ? json.message : "Submission failed.";
      const errors = (json && json.errors) ? json.errors : {};
      const err = new Error(message);
      err.fieldErrors = errors;
      throw err;
    }

    return json;
  }

  function attach(form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      clearAllErrors(form);
      setStatus(form, "", false);

      const data = collectFormData(form);
      const errors = validate(data);

      if (Object.keys(errors).length) {
        Object.entries(errors).forEach(([k, msg]) => setFieldError(form, k, msg));
        setStatus(form, "Please fix the highlighted fields.", false);

        // focus first invalid field
        const firstKey = Object.keys(errors)[0];
        const firstEl = qs(`[name="${CSS.escape(firstKey)}"]`, form);
        if (firstEl) firstEl.focus();

        return;
      }

      const btn = qs(".ws68502-submit", form);
      const oldBtnText = btn ? btn.textContent : "";
      if (btn) {
        btn.disabled = true;
        btn.textContent = "Submitting…";
      }

      setStatus(form, "Submitting…", false);

      try {
        const json = await postLead(data);
        setStatus(form, json.message || "Submitted successfully.", true);
        form.reset();
      } catch (err) {
        // If server sent field errors, show them
        if (err.fieldErrors && typeof err.fieldErrors === "object") {
          Object.entries(err.fieldErrors).forEach(([k, msg]) =>
            setFieldError(form, k, msg || "Invalid")
          );
        }
        setStatus(form, err.message || "Submission failed.", false);
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.textContent = oldBtnText || "Submit";
        }
      }
    });
  }

  function init() {
    if (typeof WS68502LeadForm === "undefined") return;
    const form = document.getElementById("ws68502LeadForm");
    if (!form) return;
    attach(form);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
