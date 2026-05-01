(function () {
    const state = {
        me: null,
        assignments: [],
        currentDate: new Date(),
        profileEditing: false
    };

    async function checkAuth() {
        const result = await db.auth.checkSession();
        const currentUser = typeof db.auth.getCurrentUser === 'function'
            ? db.auth.getCurrentUser()
            : (result.data || null);
        const isTechnician = currentUser && currentUser.role === 'technician';
        if (!result.success || !isTechnician) {
            window.location.href = 'index.html';
            return false;
        }
        return true;
    }

    async function loadMe() {
        let meResult;
        if (db.technicians && typeof db.technicians.getMe === 'function') {
            meResult = await db.technicians.getMe();
        } else {
            const response = await fetch('api/technicians.php?me=true', {
                method: 'GET',
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            meResult = await response.json();
        }
        if (!meResult.success) throw new Error(meResult.error || 'Failed to load profile');
        state.me = meResult.data;
        setText('techName', state.me.name || 'Technician');
        setText('techEmail', state.me.email || '');
        const avatar = document.getElementById('techAvatar');
        if (avatar && state.me.avatar_url) {
            avatar.innerHTML = `<img src="${state.me.avatar_url}" alt="avatar" style="width:100%;height:100%;object-fit:cover;">`;
        }
    }

    async function loadAssignments() {
        const result = await db.tickets.getAll('all');
        if (!result.success) throw new Error(result.error || 'Failed to load assignments');
        state.assignments = (result.data || []).map(t => ({
            id: t.id, service: t.service, date: t.date, time: t.time, status: t.status,
            customer: t.customer, email: t.email, phone: t.phone, address: t.address || '', description: t.description || ''
        }));
        return state.assignments;
    }

    function renderAssignments(targetId, filter) {
        const container = document.getElementById(targetId);
        if (!container) return;
        const list = filter ? state.assignments.filter(a => a.status === filter) : state.assignments;
        if (!list.length) {
            container.innerHTML = '<p>No assignments found.</p>';
            return;
        }
        container.innerHTML = list.map(a => `
            <div class="assignment-card">
                <strong>#${a.id}</strong> - ${a.service} <small>(${a.status})</small><br>
                ${a.date} ${a.time} - ${a.customer}<br>
                <small>${a.address}</small><br>
                <button class="btn btn-sm" onclick="techPortal.showAssignmentDetails('${a.id}')">View Details</button>
                <button class="btn btn-sm btn-info" onclick="openChatModal(${a.id}, '${a.id}', '${a.customer.replace(/'/g, "\\'")}')" style="background: #3b82f6; color: white;"><i class="fa-solid fa-comments"></i> Chat</button>
                ${a.status !== 'completed' ? `<button class="btn btn-sm btn-success" onclick="techPortal.confirmComplete('${a.id}')" style="float: right;">Complete</button>` : `<button class="btn btn-sm" onclick="techPortal.confirmRevert('${a.id}')" style="float: right;">Revert to Pending</button>`}
            </div>
        `).join('');
    }

    async function updateStatus(ticketId, status) {
        const result = await db.tickets.update(ticketId, { status });
        if (!result.success) throw new Error(result.error || 'Failed to update ticket');
        await loadAssignments();
        const assignmentsList = document.getElementById('assignmentsList');
        if (assignmentsList) renderAssignments('assignmentsList');
        const todayList = document.getElementById('todaySchedule');
        if (todayList) renderToday();
    }

    function confirmComplete(ticketId) {
        if (confirm('Are you sure you want to mark this assignment as completed? This will notify the customer.')) {
            updateStatus(ticketId, 'completed').catch(err => {
                console.error('Complete failed:', err);
                alert('Failed to complete: ' + (err.message || 'Unknown error'));
            });
        }
    }

    function confirmRevert(ticketId) {
        if (confirm('Are you sure you want to revert this assignment to "Pending"?')) {
            updateStatus(ticketId, 'pending').catch(err => {
                console.error('Revert failed:', err);
                alert('Failed to revert: ' + (err.message || 'Unknown error'));
            });
        }
    }

    function showAssignmentDetails(ticketId) {
        const a = state.assignments.find(x => String(x.id) === String(ticketId));
        if (!a) return;
        const content = document.getElementById('detailsContent');
        if (!content) return;
        content.innerHTML = `
            <p><strong>Ticket ID:</strong> #${a.id}</p>
            <p><strong>Service:</strong> ${a.service}</p>
            <p><strong>Status:</strong> ${a.status}</p>
            <p><strong>Date:</strong> ${a.date}</p>
            <p><strong>Time:</strong> ${a.time}</p>
            <p><strong>Customer:</strong> ${a.customer}</p>
            <p><strong>Email:</strong> ${a.email}</p>
            <p><strong>Phone:</strong> ${a.phone}</p>
            <p><strong>Address:</strong> ${a.address || 'N/A'}</p>
            <p><strong>Description:</strong> ${a.description || 'No description provided'}</p>
        `;
        document.getElementById('detailsModal').classList.add('active');
    }

    function closeDetailsModal() {
        document.getElementById('detailsModal').classList.remove('active');
    }

    function renderDashboardStats() {
        setText('totalAssignments', state.assignments.length);
        setText('pendingAssignments', state.assignments.filter(a => a.status === 'pending' || a.status === 'confirmed').length);
        setText('inProgressAssignments', state.assignments.filter(a => a.status === 'in_progress').length);
        setText('completedAssignments', state.assignments.filter(a => a.status === 'completed').length);
    }

    function renderToday() {
        const container = document.getElementById('todaySchedule');
        if (!container) return;
        const d = new Date();
        const today = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        const list = state.assignments.filter(a => a.date === today);
        container.innerHTML = list.length ? list.map(a => `<div class="assignment-card">${a.time} - ${a.service} (${a.customer})</div>`).join('') : '<p>No assignments scheduled for today.</p>';
    }

    function renderCalendar() {
        const grid = document.getElementById('calendarGrid');
        const monthLabel = document.getElementById('calendarMonth');
        if (!grid || !monthLabel) return;
        const year = state.currentDate.getFullYear();
        const month = state.currentDate.getMonth();
        monthLabel.textContent = `${state.currentDate.toLocaleString('default', { month: 'long' })} ${year}`;
        grid.innerHTML = '';
        ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => grid.insertAdjacentHTML('beforeend', `<div><strong>${d}</strong></div>`));
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        for (let i = 0; i < firstDay; i++) grid.insertAdjacentHTML('beforeend', '<div></div>');
        for (let day = 1; day <= daysInMonth; day++) {
            const date = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const has = state.assignments.some(a => a.date === date);
            grid.insertAdjacentHTML('beforeend', `<div class="calendar-day ${has ? 'has-assignments' : ''}" title="${has ? 'Has booking' : ''}">${day}</div>`);
        }
    }

    async function renderAvailability() {
        if (!state.me) return;
        const result = await db.availability.getAll({ technician_id: state.me.id });
        const container = document.getElementById('availabilitySlots');
        if (!container) return;
        if (!result.success || !result.data.length) {
            container.innerHTML = '<p>No availability rows yet.</p>';
            return;
        }
        container.innerHTML = result.data.slice(0, 30).map(row => `
            <div class="slot-row">
                <span>${row.date}</span>
                <span>${row.time_slot}</span>
                <label><input type="checkbox" ${row.is_available ? 'checked' : ''} onchange="techPortal.toggleAvailability(${row.id}, this.checked)"> Available</label>
            </div>
        `).join('');
    }

    async function toggleAvailability(id, isAvailable) {
        const result = await db.availability.update(id, { is_available: isAvailable ? 1 : 0 });
        if (!result.success) throw new Error(result.error || 'Failed to update availability');
    }

    async function init(page) {
        try {
            const ok = await checkAuth();
            if (!ok) return;
            await loadMe();
            await loadAssignments();

            if (page === 'dashboard') {
                renderDashboardStats();
                renderToday();
            } else if (page === 'assignments') {
                renderAssignments('assignmentsList');
            } else if (page === 'calendar') {
                renderCalendar();
            } else if (page === 'availability') {
                await renderAvailability();
            } else if (page === 'profile') {
                setText('profileName', state.me.name || '');
                setText('profileEmail', state.me.email || '');
                setText('profilePhone', state.me.phone || '');
                setText('profileSpecialties', (state.me.specialties || []).join(', ') || 'None');

                // Show pending changes warning if exists
                const pendingDiv = document.getElementById('profilePending');
                if (pendingDiv && state.me.pending_changes) {
                    const changes = state.me.pending_changes;
                    const changeList = Object.entries(changes)
                        .map(([key, val]) => `<strong>${key}:</strong> ${val}`)
                        .join('<br>');
                    pendingDiv.innerHTML = `<i class="fa-solid fa-clock"></i> <strong>Profile changes pending admin approval:</strong><br>${changeList}`;
                    pendingDiv.style.display = 'block';
                }
            }

            // Setup sidebar toggle for small screens
            const sidebarToggle = document.getElementById('techSidebarToggle');
            const sidebarOverlay = document.getElementById('techSidebarOverlay');
            const sidebar = document.getElementById('techSidebar');
            if (sidebarToggle && sidebar) {
                sidebarToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('open');
                    if (sidebarOverlay) sidebarOverlay.classList.toggle('active');
                });
            }
            if (sidebarOverlay && sidebar) {
                sidebarOverlay.addEventListener('click', () => {
                    sidebar.classList.remove('open');
                    sidebarOverlay.classList.remove('active');
                });
            }
        } catch (err) {
            console.error(err);
            alert(err.message || 'Failed to load technician page');
        }
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.tagName === 'INPUT') {
            el.value = value;
        } else {
            el.textContent = String(value);
        }
    }

    async function logout() {
        await db.auth.logout();
        window.location.href = 'index.html';
    }

    function changeMonth(delta) {
        state.currentDate.setMonth(state.currentDate.getMonth() + delta);
        renderCalendar();
    }

    function toggleEditMode() {
        const isEditing = state.profileEditing || false;
        state.profileEditing = !isEditing;

        const fields = ['profileName', 'profileEmail', 'profilePhone'];
        fields.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = !state.profileEditing;
        });

        const viewActions = document.getElementById('profileViewActions');
        const editActions = document.getElementById('profileEditActions');
        if (viewActions) viewActions.style.display = state.profileEditing ? 'none' : 'block';
        if (editActions) editActions.style.display = state.profileEditing ? 'flex' : 'none';

        // Hide messages
        const errorDiv = document.getElementById('profileError');
        const successDiv = document.getElementById('profileSuccess');
        const pendingDiv = document.getElementById('profilePending');
        if (errorDiv) errorDiv.style.display = 'none';
        if (successDiv) successDiv.style.display = 'none';
        if (pendingDiv) pendingDiv.style.display = 'none';

        // If cancelling, restore original values
        if (!state.profileEditing && state.me) {
            setText('profileName', state.me.name || '');
            setText('profileEmail', state.me.email || '');
            setText('profilePhone', state.me.phone || '');
        }
    }

    async function saveProfile() {
        const errorDiv = document.getElementById('profileError');
        const successDiv = document.getElementById('profileSuccess');
        if (errorDiv) errorDiv.style.display = 'none';
        if (successDiv) successDiv.style.display = 'none';

        const name = document.getElementById('profileName')?.value?.trim() || '';
        const email = document.getElementById('profileEmail')?.value?.trim() || '';
        const phone = document.getElementById('profilePhone')?.value?.trim() || '';

        // Validation: Name
        if (!name) {
            if (errorDiv) {
                errorDiv.textContent = 'Name is required';
                errorDiv.style.display = 'block';
            }
            return;
        }

        // Validation: Email format
        if (!email) {
            if (errorDiv) {
                errorDiv.textContent = 'Email is required';
                errorDiv.style.display = 'block';
            }
            return;
        }
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            if (errorDiv) {
                errorDiv.textContent = 'Please enter a valid email address';
                errorDiv.style.display = 'block';
            }
            return;
        }

        // Validation: Phone format (Philippines format: 09XXXXXXXXX or +63XXXXXXXXXX)
        if (phone) {
            const phoneClean = phone.replace(/\s+/g, '');
            const phoneRegex = /^(09\d{9}|\+63\d{10})$/;
            if (!phoneRegex.test(phoneClean)) {
                if (errorDiv) {
                    errorDiv.textContent = 'Invalid phone format. Use 09XXXXXXXXX (11 digits) or +63XXXXXXXXXX format';
                    errorDiv.style.display = 'block';
                }
                return;
            }
        }

        try {
            // Update technician profile (this will store as pending for non-admins)
            if (state.me && state.me.id) {
                const techUpdates = { name, email, phone };
                const techResult = await db.technicians.update(state.me.id, techUpdates);

                // Check if changes are pending approval
                if (techResult.pending_approval) {
                    // Exit edit mode
                    toggleEditMode();

                    if (successDiv) {
                        successDiv.innerHTML = 'Your profile changes have been submitted for <strong>admin approval</strong>. Changes will take effect once approved.';
                        successDiv.style.display = 'block';
                    }
                    return;
                }

                if (!techResult.success) throw new Error(techResult.error || 'Failed to update technician profile');
            }

            // Update user profile (name, phone, email)
            const userUpdates = {};
            if (name) userUpdates.name = name;
            if (phone) userUpdates.phone = phone;
            if (email) userUpdates.email = email;
            if (Object.keys(userUpdates).length > 0) {
                const userResult = await db.users.update(userUpdates);
                if (!userResult.success) throw new Error(userResult.error || 'Failed to update user profile');
            }

            // Refresh profile data
            await loadMe();
            setText('profileName', state.me.name || '');
            setText('profileEmail', state.me.email || '');
            setText('profilePhone', state.me.phone || '');
            setText('profileSpecialties', (state.me.specialties || []).join(', ') || 'None');

            // Exit edit mode
            toggleEditMode();

            if (successDiv) {
                successDiv.textContent = 'Profile updated successfully!';
                successDiv.style.display = 'block';
            }
        } catch (error) {
            console.error('Profile save error:', error);
            if (errorDiv) {
                errorDiv.textContent = error.message || 'Failed to update profile. Please try again.';
                errorDiv.style.display = 'block';
            }
        }
    }

    window.techPortal = { init, logout, updateStatus, confirmComplete, confirmRevert, showAssignmentDetails, closeDetailsModal, toggleAvailability, changeMonth, toggleEditMode, saveProfile };
})();
