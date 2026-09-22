/**
 * main.js – Client-side logic
 * Saragarhi Shooting Academy – 51st TN Shooting Championship
 */

/* ============================================================
   Utility helpers
   ============================================================ */
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

function showMsg(boxEl, type, text) {
  if (!boxEl) return;
  boxEl.className = `msg-box show ${type}`;
  const icons = { success: '✔', error: '✖', info: 'ℹ' };
  boxEl.innerHTML = `<span>${icons[type] || 'ℹ'}</span><span>${text}</span>`;
  boxEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearMsg(boxEl) {
  if (!boxEl) return;
  boxEl.className = 'msg-box';
  boxEl.innerHTML = '';
}

function setLoading(btn, loading) {
  const spinner = btn.querySelector('.spinner');
  const label   = btn.querySelector('.btn-label');
  btn.disabled  = loading;
  if (spinner) spinner.classList.toggle('show', loading);
  if (label)   label.style.display = loading ? 'none' : '';
}

function markField(input, valid, msg = '') {
  const group = input.closest('.form-group');
  const errEl = group?.querySelector('.field-error');
  input.classList.toggle('is-invalid', !valid);
  input.classList.toggle('is-valid',    valid);
  if (group) group.classList.toggle('has-error', !valid);
  if (errEl) errEl.textContent = valid ? '' : msg;
}

function clearField(input) {
  input.classList.remove('is-invalid', 'is-valid');
  const group = input.closest('.form-group');
  if (group) group.classList.remove('has-error');
}

/* ============================================================
   Floating Particles
   ============================================================ */
function initParticles() {
  const bg = $('.page-bg');
  if (!bg) return;
  const N = 18;
  for (let i = 0; i < N; i++) {
    const p = document.createElement('div');
    p.className = 'particle';
    p.style.cssText = [
      `left:${Math.random() * 100}%`,
      `animation-duration:${8 + Math.random() * 12}s`,
      `animation-delay:${Math.random() * -15}s`,
      `width:${1 + Math.random() * 2}px`,
      `height:${1 + Math.random() * 2}px`,
      `opacity:${0.2 + Math.random() * 0.4}`,
    ].join(';');
    bg.appendChild(p);
  }
}

/* ============================================================
   Toggle Password Visibility
   ============================================================ */
function initPasswordToggles() {
  $$('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = btn.closest('.input-wrap').querySelector('input');
      const isText = input.type === 'text';
      input.type = isText ? 'password' : 'text';
      btn.innerHTML = isText
        ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/></svg>';
    });
  });
}

/* ============================================================
   Password Strength Meter
   ============================================================ */
function initPasswordStrength() {
  const inputs = $$('#password, #fp_new_password');
  inputs.forEach(pwInput => {
    const meter = pwInput.closest('.form-group')?.querySelector('.pw-strength');
    if (!meter) return;

    pwInput.addEventListener('input', () => {
      const val = pwInput.value;
      if (!val) { meter.classList.remove('show'); return; }
      meter.classList.add('show');

      let score = 0;
      if (val.length >= 8)         score++;
      if (/[A-Z]/.test(val))       score++;
      if (/[0-9]/.test(val))       score++;
      if (/[^A-Za-z0-9]/.test(val)) score++;

      const fill   = meter.querySelector('.fill');
      const label  = meter.querySelector('.label-text');
      const levels = [
        { w: '20%', c: '#E74C3C', t: 'Weak' },
        { w: '40%', c: '#E67E22', t: 'Fair' },
        { w: '70%', c: '#F1C40F', t: 'Good' },
        { w: '100%', c: '#27AE60', t: 'Strong' },
      ];
      const l = levels[score - 1] || levels[0];
      fill.style.width      = l.w;
      fill.style.background = l.c;
      label.textContent     = `Password Strength: ${l.t}`;
      label.style.color     = l.c;
    });
  });
}

/* ============================================================
   Aadhaar Formatting (XXXX XXXX XXXX)
   ============================================================ */
function initAadhaarFormat() {
  const aInput = $('#aadhaar_number');
  if (!aInput) return;
  aInput.addEventListener('input', () => {
    let v = aInput.value.replace(/\D/g, '').substring(0, 12);
    aInput.value = v.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
    aInput.setAttribute('data-raw', v);
  });
}

/* ============================================================
   Phone – digits only
   ============================================================ */
function initPhoneFormat() {
  const ph = $('#phone');
  if (!ph) return;
  ph.addEventListener('input', () => {
    ph.value = ph.value.replace(/\D/g, '').substring(0, 10);
  });
}

/* ============================================================
   Form Validators
   ============================================================ */
const validators = {
  required: (v) => v.trim().length > 0,
  email:    (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()),
  phone:    (v) => /^[6-9]\d{9}$/.test(v.replace(/\D/g, '')),
  aadhaar:  (v) => /^\d{12}$/.test(v.replace(/\s/g, '')),
  minLen:   (v, n) => v.trim().length >= n,
  name:     (v) => /^[A-Za-z\s.'-]{2,}$/.test(v.trim()),
  name1:    (v) => /^[A-Za-z\s.'-]{1,}$/.test(v.trim()),
  dob:      (v) => {
    if (!v) return false;
    const d = new Date(v);
    const now = new Date();
    const age = (now - d) / (1000 * 60 * 60 * 24 * 365.25);
    return age >= 12 && age <= 100;
  },
};

function validateInput(input) {
  const rules = (input.dataset.rules || '').split('|').filter(Boolean);
  const isRequired = rules.includes('required');
  const value = input.value.trim();

  if (value === '') {
    if (isRequired) {
      markField(input, false, 'This field is required.');
      return false;
    } else {
      clearField(input);
      return true;
    }
  }

  for (const rule of rules) {
    if (rule === 'required') continue;
    const [name, param] = rule.split(':');
    let testVal = input.value;
    if (input.id === 'aadhaar_number') testVal = testVal.replace(/\s/g, '');

    if (!validators[name]?.(testVal, param ? parseInt(param) : undefined)) {
      const msgs = {
        email:    'Enter a valid email address.',
        phone:    'Enter a valid 10-digit mobile number.',
        aadhaar:  'Aadhaar must be 12 digits.',
        minLen:   `Minimum ${param} characters required.`,
        name:     'Enter a valid name (letters only).',
        name1:    'Enter a valid name (letters only).',
        dob:      'You must be at least 12 years old to register.',
      };
      markField(input, false, msgs[name] || 'Invalid value.');
      return false;
    }
  }
  markField(input, true);
  return true;
}

function validateAll(form) {
  let ok = true;
  $$(  'input[data-rules], select[data-rules], textarea[data-rules]', form)
    .forEach(inp => { if (!validateInput(inp)) ok = false; });

  // Password confirmation
  const pw   = $('#password', form);
  const cpw  = $('#confirm_password', form);
  if (pw && cpw && cpw.value !== pw.value) {
    markField(cpw, false, 'Passwords do not match.');
    ok = false;
  }
  return ok;
}

/* ============================================================
   LOGIN FORM
   ============================================================ */
function initLoginForm() {
  const form = $('#loginForm');
  if (!form) return;

  const msgBox = $('#msgBox');

  // Inline validation on blur
  $$('input[data-rules]', form).forEach(inp =>
    inp.addEventListener('blur', () => validateInput(inp))
  );

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearMsg(msgBox);
    if (!validateAll(form)) return;

    const btn = form.querySelector('[type=submit]');
    setLoading(btn, true);

    try {
      const resp = await fetch('actions/login_action.php', {
        method: 'POST',
        body:   new FormData(form),
      });
      const data = await resp.json();
      if (data.success) {
        showMsg(msgBox, 'success', 'Login successful! Redirecting…');
        // Use replace() so the login page is NOT added to browser history.
        // This prevents the back-swipe from returning to the login form.
        window.location.replace(data.redirect || 'dashboard.php');
      } else {
        showMsg(msgBox, 'error', data.message || 'Invalid credentials.');
      }
    } catch {
      showMsg(msgBox, 'error', 'Network error. Please try again.');
    } finally {
      setLoading(btn, false);
    }
  });
}

/* ============================================================
   REGISTER FORM
   ============================================================ */
function initRegisterForm() {
  const form = $('#registerForm');
  if (!form) return;

  const msgBox = $('#msgBox');

  // Auto-uppercase text details fields as user types
  const uppercaseFields = ['first_name', 'last_name', 'father_guardian_name', 'address', 'club_name', 'association', 'membership_id'];
  uppercaseFields.forEach(id => {
    const inp = form.querySelector(`#${id}`);
    if (inp) {
      inp.addEventListener('input', () => {
        const start = inp.selectionStart;
        const end = inp.selectionEnd;
        inp.value = inp.value.toUpperCase();
        if (start !== null && end !== null) {
          inp.setSelectionRange(start, end);
        }
      });
    }
  });

  // Real-time blur validation
  $$('input[data-rules], select[data-rules], textarea[data-rules]', form)
    .forEach(inp => {
      inp.addEventListener('blur',  () => validateInput(inp));
      inp.addEventListener('input', () => {
        if (inp.classList.contains('is-invalid')) validateInput(inp);
      });
    });

  // Handle OTP request
  const sendOtpBtn = form.querySelector('#sendOtpBtn');
  if (sendOtpBtn) {
    sendOtpBtn.addEventListener('click', async () => {
      const emailInput = form.querySelector('#email');
      clearMsg(msgBox);
      
      if (!validateInput(emailInput)) {
        showMsg(msgBox, 'error', 'Please enter a valid email address before sending OTP.');
        emailInput.focus();
        return;
      }
      
      setLoading(sendOtpBtn, true);
      const email = emailInput.value.trim();
      const csrfToken = form.querySelector('[name=csrf_token]').value;
      
      let isSuccess = false;
      try {
        const resp = await fetch('actions/send_otp.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `email=${encodeURIComponent(email)}&csrf_token=${encodeURIComponent(csrfToken)}`
        });
        const data = await resp.json();
        if (data.success) {
          showMsg(msgBox, 'success', data.message || 'Verification code sent.');
          isSuccess = true;
          
          let cooldown = 60;
          sendOtpBtn.disabled = true;
          const labelSpan = sendOtpBtn.querySelector('.btn-label');
          if (labelSpan) labelSpan.textContent = `Resend in ${cooldown}s`;
          else sendOtpBtn.textContent = `Resend in ${cooldown}s`;
          
          const timer = setInterval(() => {
            cooldown--;
            if (cooldown <= 0) {
              clearInterval(timer);
              sendOtpBtn.disabled = false;
              if (labelSpan) labelSpan.textContent = 'Resend OTP';
              else sendOtpBtn.textContent = 'Resend OTP';
            } else {
              sendOtpBtn.disabled = true;
              if (labelSpan) labelSpan.textContent = `Resend in ${cooldown}s`;
              else sendOtpBtn.textContent = `Resend in ${cooldown}s`;
            }
          }, 1000);
        } else {
          showMsg(msgBox, 'error', data.message || 'Failed to send OTP. Please try again.');
        }
      } catch {
        showMsg(msgBox, 'error', 'Network error. Please try again.');
      } finally {
        setLoading(sendOtpBtn, false);
        if (isSuccess) {
          sendOtpBtn.disabled = true;
        }
      }
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearMsg(msgBox);
    if (!validateAll(form)) {
      showMsg(msgBox, 'error', 'Please fix the errors above before submitting.');
      const firstErr = form.querySelector('.is-invalid');
      firstErr?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    const btn = form.querySelector('[type=submit]');
    setLoading(btn, true);

    const fd = new FormData(form);
    // Send raw aadhaar (no spaces)
    const aInput = $('#aadhaar_number', form);
    if (aInput) fd.set('aadhaar_number', aInput.value.replace(/\s/g, ''));

    try {
      const resp = await fetch('actions/register_action.php', {
        method: 'POST',
        body: fd,
      });
      const data = await resp.json();
      if (data.success) {
        showMsg(msgBox, 'success', 'Registration successful! Redirecting to login…');
        window.location.href = 'login.php?registered=1';
      } else {
        showMsg(msgBox, 'error', data.message || 'Registration failed.');
      }
    } catch {
      showMsg(msgBox, 'error', 'Network error. Please try again.');
    } finally {
      setLoading(btn, false);
    }
  });
}

function initEditProfileForm() {
  const form = $('#editProfileForm');
  if (!form) return;

  const msgBox = $('#msgBox');

  // Auto-uppercase text details fields as user types
  const uppercaseFields = ['first_name', 'last_name', 'father_guardian_name', 'address', 'club_name', 'association', 'membership_id'];
  uppercaseFields.forEach(id => {
    const inp = form.querySelector(`#${id}`);
    if (inp) {
      inp.addEventListener('input', () => {
        const start = inp.selectionStart;
        const end = inp.selectionEnd;
        inp.value = inp.value.toUpperCase();
        if (start !== null && end !== null) {
          inp.setSelectionRange(start, end);
        }
      });
    }
  });

  $$('input[data-rules], select[data-rules], textarea[data-rules]', form)
    .forEach(inp => {
      inp.addEventListener('blur',  () => validateInput(inp));
      inp.addEventListener('input', () => {
        if (inp.classList.contains('is-invalid')) validateInput(inp);
      });
    });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearMsg(msgBox);
    if (!validateAll(form)) {
      showMsg(msgBox, 'error', 'Please fix the errors above before saving.');
      const firstErr = form.querySelector('.is-invalid');
      firstErr?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    const btn = form.querySelector('[type=submit]');
    setLoading(btn, true);

    const fd = new FormData(form);
    const aInput = $('#aadhaar_number', form);
    if (aInput) fd.set('aadhaar_number', aInput.value.replace(/\s/g, ''));

    try {
      const resp = await fetch('actions/edit_profile_action.php', {
        method: 'POST',
        body: fd,
      });
      const data = await resp.json();
      if (data.success) {
        showMsg(msgBox, 'success', data.message || 'Profile updated successfully.');
        window.location.href = 'dashboard.php?tab=profile&updated=1';
      } else {
        showMsg(msgBox, 'error', data.message || 'Could not save profile.');
      }
    } catch {
      showMsg(msgBox, 'error', 'Network error. Please try again.');
    } finally {
      setLoading(btn, false);
    }
  });
}

/* ============================================================
   FORGOT PASSWORD
   ============================================================ */
function initForgotPassword() {
  const form1 = $('#fpStep1');
  const form2 = $('#fpStep2');
  if (!form1) return;

  const msgBox = $('#msgBox');
  let userEmail = '';

  // Step 1 – Send OTP
  form1.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearMsg(msgBox);
    const emailInput = $('#fp_email');
    if (!validateInput(emailInput)) return;

    const btn = form1.querySelector('[type=submit]');
    setLoading(btn, true);

    try {
      const fd = new FormData(form1);
      const resp = await fetch('actions/forgot_action.php', { method: 'POST', body: fd });
      const data = await resp.json();
      if (data.success) {
        userEmail = emailInput.value.trim();
        showMsg(msgBox, 'success', data.message);
        form1.style.display = 'none';
        form2.style.display = 'block';
        
        // Auto-fill read-only email field in step 2
        const fpEmailStep2 = $('#fp_email_step2');
        if (fpEmailStep2) {
          fpEmailStep2.value = userEmail;
        }

        // Mark step 1 done
        const s1 = document.querySelector('.step:nth-child(1)');
        const s2 = document.querySelector('.step:nth-child(3)');
        const c1 = document.querySelector('.connector');
        if (s1) s1.classList.replace('active', 'done');
        if (s2) s2.classList.add('active');
        if (c1) c1.classList.add('done');
      } else {
        showMsg(msgBox, 'error', data.message);
      }
    } catch {
      showMsg(msgBox, 'error', 'Network error. Please try again.');
    } finally {
      setLoading(btn, false);
    }
  });

  // Step 2 – Reset password
  if (!form2) return;
  form2.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearMsg(msgBox);
    if (!validateAll(form2)) return;

    const pw  = $('#fp_new_password', form2).value;
    const cpw = $('#fp_confirm_password', form2).value;
    if (pw !== cpw) {
      markField($('#fp_confirm_password', form2), false, 'Passwords do not match.');
      return;
    }

    const btn = form2.querySelector('[type=submit]');
    setLoading(btn, true);

    try {
      const fd = new FormData(form2);
      fd.set('email', userEmail);
      fd.append('step', '2');
      const resp = await fetch('actions/forgot_action.php', { method: 'POST', body: fd });
      const data = await resp.json();
      if (data.success) {
        showMsg(msgBox, 'success', 'Password reset successful! Redirecting to login…');
        window.location.replace('login.php');
      } else {
        showMsg(msgBox, 'error', data.message);
      }
    } catch {
      showMsg(msgBox, 'error', 'Network error. Please try again.');
    } finally {
      setLoading(btn, false);
    }
  });
}

/* ============================================================
   Google Form-style File Upload Handling
   ============================================================ */
function initFileUploads() {
  document.querySelectorAll('.upload-container').forEach(container => {
    const input = container.querySelector('input[type=file]');
    const box = container.querySelector('.upload-box');
    const info = container.querySelector('.upload-file-info');
    const fileNameSpan = container.querySelector('.file-name');
    
    if (!input || !box) return;
    
    input.addEventListener('change', () => {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        fileNameSpan.textContent = file.name;
        info.style.display = 'flex';
        box.classList.add('has-file');
      } else {
        fileNameSpan.textContent = '';
        info.style.display = 'none';
        box.classList.remove('has-file');
      }
      // Trigger validation on change
      validateInput(input);
    });
  });
}

window.clearUpload = function(id) {
  const input = document.getElementById(id);
  if (!input) return;
  input.value = '';
  const event = new Event('change');
  input.dispatchEvent(event);
};

/* ============================================================
   Boot
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  initParticles();
  initPasswordToggles();
  initPasswordStrength();
  initAadhaarFormat();
  initPhoneFormat();
  initLoginForm();
  initRegisterForm();
  initEditProfileForm();
  initForgotPassword();
  initFileUploads();

  // Show flash messages from PHP redirect
  const params = new URLSearchParams(location.search);
  const msgBox = $('#msgBox');
  if (params.get('registered') === '1') {
    showMsg(msgBox, 'success', 'Registration complete! Please login with your credentials.');
  }
  if (params.get('logout') === '1') {
    showMsg(msgBox, 'info', 'You have been logged out successfully.');
  }
  if (params.get('expired') === '1') {
    showMsg(msgBox, 'error', 'Your session has expired. Please login again.');
  }
});
