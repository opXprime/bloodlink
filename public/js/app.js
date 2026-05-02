document.addEventListener('DOMContentLoaded', function() {

    // =========================================================================
    // AUTO-DISMISS ALERTS (fade out after 5 seconds)
    // =========================================================================
    document.querySelectorAll('.alert-dismissible').forEach(function(a) {
        setTimeout(function() {
            a.style.transition = 'opacity .5s';
            a.style.opacity = '0';
            setTimeout(() => a.remove(), 500);
        }, 5000);
    });

    document.querySelectorAll('.btn-close').forEach(function(b) {
        b.onclick = function() { this.closest('.alert').remove(); };
    });

    // =========================================================================
    // DROPDOWN TOGGLE (close on outside click)
    // =========================================================================
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
            if (!m.parentElement.contains(e.target)) m.classList.remove('show');
        });
    });

    // =========================================================================
    // NAVBAR MOBILE TOGGLE
    // =========================================================================
    var tog = document.querySelector('.navbar-toggler');
    if (tog) {
        tog.onclick = function() {
            document.getElementById('navbarContent').classList.toggle('show');
        };
    }

    // =========================================================================
    // PASSWORD MATCH VALIDATION (confirm password field)
    // =========================================================================
    var cp = document.getElementById('confirm_password');
    if (cp) {
        cp.addEventListener('input', function() {
            var p = document.getElementById('password');
            this.setCustomValidity(p && this.value !== p.value ? 'Passwords do not match' : '');
        });
    }

    // =========================================================================
    // PASSWORD STRENGTH VALIDATION (min 8 chars, 1 upper, 1 lower)
    // =========================================================================
    var pw = document.getElementById('password');
    if (pw) {
        pw.addEventListener('input', function() {
            var v = this.value;
            var ok = v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v);
            this.setCustomValidity(ok ? '' : 'Min 8 characters with at least 1 uppercase and 1 lowercase letter');
        });
    }

    // =========================================================================
    // PDF FILE VALIDATION (type + size check for hospital verification docs)
    // =========================================================================
    var fi = document.getElementById('verification_doc');
    if (fi) {
        fi.addEventListener('change', function() {
            var f = this.files[0];
            if (f) {
                if (f.type !== 'application/pdf') { alert('Only PDF files allowed.'); this.value = ''; return; }
                if (f.size > 5 * 1024 * 1024)    { alert('Max 5MB.'); this.value = ''; return; }
            }
        });
    }

    // =========================================================================
    // LOCATION CASCADE: Country → City → Area
    // =========================================================================
    initLocationCascade();
});


/**
 * Cascading location picker
 * Country dropdown → fetches cities via AJAX
 * City dropdown → enables area autocomplete via AJAX
 */
function initLocationCascade() {
    var countrySelect = document.getElementById('loc_country');
    var citySelect    = document.getElementById('loc_city');
    var areaInput     = document.getElementById('loc_area_text');
    var areaIdField   = document.getElementById('loc_area_id');
    var acList        = document.getElementById('loc_ac_list');

    if (!countrySelect || !citySelect || !areaInput) return;

    var baseUrl = document.querySelector('meta[name="app-url"]');
    var appUrl  = baseUrl ? baseUrl.content : '/bloodbank';

    // ---- Country change → load cities ----
    countrySelect.addEventListener('change', function() {
        citySelect.innerHTML = '<option value="">-- Select City --</option>';
        areaInput.value = '';
        if (areaIdField) areaIdField.value = '';
        if (acList) acList.style.display = 'none';

        var cid = this.value;
        if (!cid) return;

        fetch(appUrl + '/api/cities.php?country_id=' + encodeURIComponent(cid))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.cities) {
                    data.cities.forEach(function(c) {
                        var opt = document.createElement('option');
                        opt.value = c.id;
                        opt.textContent = c.name;
                        citySelect.appendChild(opt);
                    });
                }
            })
            .catch(function(err) { console.error('City fetch error', err); });
    });

    // ---- City change → reset area ----
    citySelect.addEventListener('change', function() {
        areaInput.value = '';
        if (areaIdField) areaIdField.value = '';
        if (acList) acList.style.display = 'none';
    });

    // ---- Area autocomplete (triggers on input and focus) ----
    var debounce = null;

    function fetchAreas(q) {
        var cityId = citySelect.value;
        if (!cityId) { if (acList) acList.style.display = 'none'; return; }

        clearTimeout(debounce);
        debounce = setTimeout(function() {
            var url = appUrl + '/api/areas.php?city_id=' + encodeURIComponent(cityId);
            if (q && q.length > 0) url += '&q=' + encodeURIComponent(q);

            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!acList) return;
                    acList.innerHTML = '';

                    if (data.areas && data.areas.length > 0) {
                        data.areas.forEach(function(a) {
                            var div = document.createElement('div');
                            div.className = 'ac-item';
                            div.textContent = a.name;
                            div.dataset.id = a.id;
                            div.onclick = function() {
                                areaInput.value = a.name;
                                if (areaIdField) areaIdField.value = a.id;
                                acList.style.display = 'none';
                            };
                            acList.appendChild(div);
                        });
                        acList.style.display = 'block';
                    } else {
                        acList.style.display = 'none';
                    }
                })
                .catch(function(err) { console.error('Area fetch error', err); });
        }, 150);
    }

    // Search as user types
    areaInput.addEventListener('input', function() {
        if (areaIdField) areaIdField.value = '';
        fetchAreas(this.value.trim());
    });

    // Show all areas on focus so user sees options before typing
    areaInput.addEventListener('focus', function() {
        if (citySelect.value) fetchAreas('');
    });

    // Close autocomplete on outside click
    document.addEventListener('click', function(e) {
        if (acList && !areaInput.contains(e.target) && !acList.contains(e.target)) {
            acList.style.display = 'none';
        }
    });

    // Keyboard navigation for autocomplete (up/down/enter)
    areaInput.addEventListener('keydown', function(e) {
        if (!acList || acList.style.display === 'none') return;
        var items = acList.querySelectorAll('.ac-item');
        var active = acList.querySelector('.ac-item.active');
        var idx = -1;
        items.forEach(function(it, i) { if (it.classList.contains('active')) idx = i; });

        if (e.key === 'ArrowDown')      { e.preventDefault(); idx = Math.min(idx + 1, items.length - 1); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); idx = Math.max(idx - 1, 0); }
        else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); items[idx].click(); return; }
        else return;

        items.forEach(function(it) { it.classList.remove('active'); });
        if (items[idx]) items[idx].classList.add('active');
    });
}


/**
 * Password strength meter
 * Checks length, uppercase, lowercase, numbers, special chars
 * Updates the visual bar and hint text
 */
function checkPwStrength(pw) {
    var bar  = document.getElementById('pw-bar');
    var hint = document.getElementById('pw-hint');
    if (!bar) return;

    // Score based on complexity criteria
    var score = 0;
    if (pw.length >= 8)        score++;
    if (pw.length >= 12)       score++;
    if (/[A-Z]/.test(pw))      score++;
    if (/[a-z]/.test(pw))      score++;
    if (/[0-9]/.test(pw))      score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    var pct   = Math.min(100, (score / 6) * 100);
    var color = score <= 2 ? '#dc3545' : score <= 4 ? '#ffc107' : '#28a745';
    var label = score <= 2 ? 'Weak' : score <= 4 ? 'Moderate' : 'Strong';

    bar.style.width = pct + '%';
    bar.style.background = color;
    if (hint) hint.textContent = label + ' — min 8 chars, 1 uppercase, 1 lowercase';
}