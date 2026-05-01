/**
 * Database API Wrapper
 * Enhanced with error handling, request timeouts, and retry logic
 */

const API_BASE = 'api/';
const REQUEST_TIMEOUT = 30000; // 30 seconds
const MAX_RETRIES = 3;

// Custom error class for API errors
class APIError extends Error {
    constructor(message, status, code) {
        super(message);
        this.name = 'APIError';
        this.status = status;
        this.code = code;
    }
}

/**
 * Fetch wrapper with timeout and retry logic
 */
async function fetchWithTimeout(url, options = {}, retries = 0) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT);
    
    try {
        const response = await fetch(url, {
            ...options,
            signal: controller.signal,
            credentials: 'include', // Always include cookies
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest', // Prevent CSRF in some frameworks
                ...options.headers
            }
        });
        
        clearTimeout(timeoutId);
        
        // Handle HTTP errors
        if (!response.ok) {
            let errorData;
            try {
                errorData = await response.json();
            } catch {
                errorData = { error: `HTTP ${response.status}: ${response.statusText}` };
            }
            throw new APIError(
                errorData.error || 'Request failed',
                response.status,
                errorData.code
            );
        }
        
        return response;
        
    } catch (error) {
        clearTimeout(timeoutId);
        
        // Retry on network errors (not on 4xx/5xx responses)
        if (retries < MAX_RETRIES && 
            (error.name === 'TypeError' || error.name === 'AbortError')) {
            const delay = Math.pow(2, retries) * 1000; // Exponential backoff
            await new Promise(resolve => setTimeout(resolve, delay));
            return fetchWithTimeout(url, options, retries + 1);
        }
        
        throw error;
    }
}

/**
 * Parse JSON response with error handling
 */
async function parseResponse(response) {
    try {
        const data = await response.json();
        
        // Check for API-level errors
        if (data.success === false) {
            throw new APIError(
                data.error || 'Request failed',
                response.status,
                data.code
            );
        }
        
        return data;
    } catch (error) {
        if (error instanceof APIError) throw error;
        throw new APIError('Invalid JSON response', 500, 'PARSE_ERROR');
    }
}

const db = {
    // Auth operations
    auth: {
        async register(userData) {
            // Client-side validation
            if (!userData.name || userData.name.length < 2) {
                throw new APIError('Name must be at least 2 characters', 400, 'VALIDATION_ERROR');
            }
            if (!userData.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(userData.email)) {
                throw new APIError('Invalid email format', 400, 'VALIDATION_ERROR');
            }
            if (!userData.password || userData.password.length < 8) {
                throw new APIError('Password must be at least 8 characters', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}auth.php?action=register`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: userData.name.trim(),
                    email: userData.email.toLowerCase().trim(),
                    password: userData.password
                })
            });
            
            return parseResponse(response);
        },
        
        async login(credentials) {
            if (!credentials.email || !credentials.password) {
                throw new APIError('Email and password are required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}auth.php?action=login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: credentials.email.toLowerCase().trim(),
                    password: credentials.password
                })
            });
            
            const data = await parseResponse(response);
            
            if (data.success && data.data) {
                // Store user data securely (not password)
                const userData = { ...data.data };
                delete userData.password; // Ensure password is never stored
                localStorage.setItem('currentUser', JSON.stringify(userData));
                
                // Set session expiration check
                const expiration = Date.now() + (24 * 60 * 60 * 1000); // 24 hours
                localStorage.setItem('sessionExpiration', expiration.toString());
            }
            
            return data;
        },
        
        async logout() {
            try {
                await fetchWithTimeout(`${API_BASE}auth.php?action=logout`, {
                    method: 'GET'
                });
            } catch (error) {
                console.error('Logout API error:', error);
            } finally {
                // Always clear local storage
                localStorage.removeItem('currentUser');
                localStorage.removeItem('sessionExpiration');
                sessionStorage.clear();
            }
        },
        
        async checkSession() {
            // Check local expiration first
            const expiration = localStorage.getItem('sessionExpiration');
            if (expiration && Date.now() > parseInt(expiration)) {
                localStorage.removeItem('currentUser');
                localStorage.removeItem('sessionExpiration');
                return { success: false, error: 'Session expired', code: 'SESSION_EXPIRED' };
            }
            
            const response = await fetchWithTimeout(`${API_BASE}auth.php?action=check`, {
                method: 'GET'
            });
            
            const data = await parseResponse(response);
            
            // Update expiration on successful check
            if (data.success) {
                const newExpiration = Date.now() + (24 * 60 * 60 * 1000);
                localStorage.setItem('sessionExpiration', newExpiration.toString());
            } else {
                // Clear on failed check
                localStorage.removeItem('currentUser');
                localStorage.removeItem('sessionExpiration');
            }
            
            return data;
        },
        
        // Get current user from storage with validation
        getCurrentUser() {
            try {
                const userJson = localStorage.getItem('currentUser');
                if (!userJson) return null;
                
                const user = JSON.parse(userJson);
                
                // Validate expiration
                const expiration = localStorage.getItem('sessionExpiration');
                if (expiration && Date.now() > parseInt(expiration)) {
                    this.logout();
                    return null;
                }
                
                return user;
            } catch {
                return null;
            }
        },
        
        // Check if user is admin
        isAdmin() {
            const user = this.getCurrentUser();
            return user && user.role === 'admin';
        },
        
        // Check if user is technician
        isTechnician() {
            const user = this.getCurrentUser();
            return user && user.role === 'technician';
        }
    },
    
    // Ticket operations
    tickets: {
        async getAll(filter = 'all') {
            if (!filter) filter = 'all';
            filter = String(filter).trim().toLowerCase();
            if (filter === 'in-progress') filter = 'in_progress';
            const allowedFilters = ['all', 'pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
            if (!allowedFilters.includes(filter)) filter = 'all';
            
            const response = await fetchWithTimeout(
                `${API_BASE}tickets.php?status=${encodeURIComponent(filter)}`,
                { method: 'GET' }
            );
            
            return parseResponse(response);
        },
        
        async create(ticketData) {
            // Validation
            const required = ['firstName', 'lastName', 'service', 'date', 'time', 'email', 'phone'];
            const missing = required.filter(field => !ticketData[field]);
            
            if (missing.length > 0) {
                throw new APIError(`Missing required fields: ${missing.join(', ')}`, 400, 'VALIDATION_ERROR');
            }
            
            // Email validation
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ticketData.email)) {
                throw new APIError('Invalid email format', 400, 'VALIDATION_ERROR');
            }
            
            // Date validation (must be today or future)
            const selectedDate = new Date(ticketData.date);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (selectedDate < today) {
                throw new APIError('Date must be today or in the future', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}tickets.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    firstName: ticketData.firstName.trim(),
                    lastName: ticketData.lastName.trim(),
                    service: ticketData.service,
                    date: ticketData.date,
                    time: ticketData.time,
                    email: ticketData.email.toLowerCase().trim(),
                    phone: ticketData.phone.trim(),
                    address: (ticketData.address || '').trim(),
                    description: (ticketData.description || '').trim()
                })
            });
            
            return parseResponse(response);
        },
        
        async update(id, updates) {
            if (!id) {
                throw new APIError('Ticket ID is required', 400, 'VALIDATION_ERROR');
            }
            
            // Filter allowed fields
            const allowedFields = ['status', 'service', 'date', 'time', 'address', 'description', 'firstName', 'lastName', 'email', 'phone', 'assigned_technician_id'];
            const filteredUpdates = {};

            for (const [key, value] of Object.entries(updates)) {
                if (allowedFields.includes(key)) {
                    filteredUpdates[key] = typeof value === 'string' ? value.trim() : value;
                }
            }
            
            if (Object.keys(filteredUpdates).length === 0) {
                throw new APIError('No valid fields to update', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}tickets.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, ...filteredUpdates })
            });
            
            return parseResponse(response);
        },
        
        async delete(id) {
            if (!id) {
                throw new APIError('Ticket ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(
                `${API_BASE}tickets.php?id=${encodeURIComponent(id)}`,
                { method: 'DELETE' }
            );
            
            return parseResponse(response);
        }
    },
    
    // User operations
    users: {
        async getAll(options = {}) {
            const params = new URLSearchParams({ list: '1' });
            if (options.limit) params.append('limit', String(options.limit));
            if (options.offset) params.append('offset', String(options.offset));

            const response = await fetchWithTimeout(`${API_BASE}users.php?${params.toString()}`, { method: 'GET' });
            return parseResponse(response);
        },

        async create(userData) {
            if (!userData.name || !userData.email || !userData.password) {
                throw new APIError('Name, email, and password are required', 400, 'VALIDATION_ERROR');
            }
            const response = await fetchWithTimeout(`${API_BASE}users.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: userData.name.trim(),
                    email: userData.email.toLowerCase().trim(),
                    password: userData.password,
                    role: (userData.role || 'user').toLowerCase(),
                    address: (userData.address || '').trim(),
                    phone: (userData.phone || '').trim()
                })
            });
            return parseResponse(response);
        },

        async get(email = null) {
            let url = `${API_BASE}users.php`;
            if (email) {
                url += `?email=${encodeURIComponent(email.toLowerCase().trim())}`;
            }
            
            const response = await fetchWithTimeout(url, { method: 'GET' });
            return parseResponse(response);
        },
        
        async update(updates) {
            // Filter and validate updates
            const allowedFields = ['name', 'address', 'phone', 'password', 'role', 'email'];
            const filteredUpdates = {};
            
            for (const [key, value] of Object.entries(updates)) {
                if (!allowedFields.includes(key)) continue;
                
                if (key === 'name' && value.length > 100) {
                    throw new APIError('Name too long', 400, 'VALIDATION_ERROR');
                }
                if (key === 'address' && value.length > 500) {
                    throw new APIError('Address too long', 400, 'VALIDATION_ERROR');
                }
                if (key === 'password' && value.length < 8) {
                    throw new APIError('Password too short', 400, 'VALIDATION_ERROR');
                }
                
                filteredUpdates[key] = typeof value === 'string' ? value.trim() : value;
            }
            
            if (Object.keys(filteredUpdates).length === 0) {
                throw new APIError('No valid fields to update', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}users.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(filteredUpdates)
            });
            
            // Update local storage if updating own profile
            const currentUser = db.auth.getCurrentUser();
            if (currentUser && updates.name && !updates.email) {
                currentUser.name = updates.name;
                localStorage.setItem('currentUser', JSON.stringify(currentUser));
            }
            
            return parseResponse(response);
        },
        
        async delete(email) {
            if (!email) {
                throw new APIError('Email is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(
                `${API_BASE}users.php?email=${encodeURIComponent(email.toLowerCase().trim())}`,
                { method: 'DELETE' }
            );
            
            return parseResponse(response);
        }
    },
    
    // Message operations
    messages: {
        async getAll(type = 'contact', options = {}) {
            const allowedTypes = ['contact', 'chat'];
            if (!allowedTypes.includes(type)) {
                throw new APIError('Invalid message type', 400, 'VALIDATION_ERROR');
            }
            
            const params = new URLSearchParams({ type });
            if (options.limit) params.append('limit', options.limit);
            if (options.offset) params.append('offset', options.offset);
            
            const response = await fetchWithTimeout(
                `${API_BASE}messages.php?${params}`,
                { method: 'GET' }
            );
            
            return parseResponse(response);
        },
        
        async send(messageData) {
            // Contact form validation
            if (messageData.name !== undefined) {
                if (!messageData.name || messageData.name.length < 2) {
                    throw new APIError('Name is required', 400, 'VALIDATION_ERROR');
                }
                if (!messageData.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(messageData.email)) {
                    throw new APIError('Valid email is required', 400, 'VALIDATION_ERROR');
                }
                if (!messageData.message || messageData.message.length < 10) {
                    throw new APIError('Message must be at least 10 characters', 400, 'VALIDATION_ERROR');
                }
                if (messageData.message.length > 5000) {
                    throw new APIError('Message too long (max 5000 characters)', 400, 'VALIDATION_ERROR');
                }
            } 
            // Chat validation
            else {
                if (!messageData.message || messageData.message.trim().length === 0) {
                    throw new APIError('Message cannot be empty', 400, 'VALIDATION_ERROR');
                }
                if (messageData.message.length > 2000) {
                    throw new APIError('Message too long (max 2000 characters)', 400, 'VALIDATION_ERROR');
                }
            }
            
            const response = await fetchWithTimeout(`${API_BASE}messages.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(messageData)
            });
            
            return parseResponse(response);
        },
        
        async delete(id, type = 'contact') {
            if (!id || typeof id !== 'number') {
                throw new APIError('Valid message ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(
                `${API_BASE}messages.php?id=${encodeURIComponent(id)}&type=${encodeURIComponent(type)}`,
                { method: 'DELETE' }
            );
            
            return parseResponse(response);
        }
    },
    
    // Services operations
    services: {
        async getAll(activeOnly = true) {
            const url = `${API_BASE}services.php${activeOnly ? '?active=true' : ''}`;
            const response = await fetchWithTimeout(url, { method: 'GET' });
            return parseResponse(response);
        },
        
        async create(serviceData) {
            const required = ['name', 'description', 'base_price', 'duration_minutes', 'category'];
            const missing = required.filter(field => !serviceData[field]);
            
            if (missing.length > 0) {
                throw new APIError(`Missing required fields: ${missing.join(', ')}`, 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}services.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(serviceData)
            });
            
            return parseResponse(response);
        },
        
        async update(id, updates) {
            if (!id) {
                throw new APIError('Service ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}services.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, ...updates })
            });
            
            return parseResponse(response);
        },
        
        async delete(id) {
            if (!id) {
                throw new APIError('Service ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(
                `${API_BASE}services.php?id=${encodeURIComponent(id)}`,
                { method: 'DELETE' }
            );
            
            return parseResponse(response);
        }
    },
    
    // Technicians operations
    technicians: {
        async getAll(options = {}) {
            const params = new URLSearchParams();
            
            if (options.service) params.append('service', options.service);
            if (options.date) params.append('date', options.date);
            if (options.time_slot) params.append('time_slot', options.time_slot);
            if (options.active !== undefined) params.append('active', options.active.toString());
            
            const url = `${API_BASE}technicians.php${params.toString() ? '?' + params.toString() : ''}`;
            const response = await fetchWithTimeout(url, { method: 'GET' });
            
            return parseResponse(response);
        },
        
        async getMe() {
            const response = await fetchWithTimeout(`${API_BASE}technicians.php?me=true`, { method: 'GET' });
            return parseResponse(response);
        },
        
        async create(technicianData) {
            const required = ['name', 'email', 'phone', 'specialties', 'password'];
            const missing = required.filter(field => !technicianData[field]);
            
            if (missing.length > 0) {
                throw new APIError(`Missing required fields: ${missing.join(', ')}`, 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}technicians.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(technicianData)
            });
            
            return parseResponse(response);
        },
        
        async update(id, updates) {
            if (!id) {
                throw new APIError('Technician ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}technicians.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, ...updates })
            });
            
            return parseResponse(response);
        },
        
        async delete(id) {
            if (!id) {
                throw new APIError('Technician ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(
                `${API_BASE}technicians.php?id=${encodeURIComponent(id)}`,
                { method: 'DELETE' }
            );
            
            return parseResponse(response);
        },
        
        async permanentDelete(id) {
            if (!id) {
                throw new APIError('Technician ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(
                `${API_BASE}technicians.php?id=${encodeURIComponent(id)}&permanent=1`,
                { method: 'DELETE' }
            );
            
            return parseResponse(response);
        }
    },
    
    // Availability operations
    availability: {
        async getAll(options = {}) {
            const params = new URLSearchParams();
            
            if (options.technician_id) params.append('technician_id', options.technician_id);
            if (options.date) params.append('date', options.date);
            if (options.service) params.append('service', options.service);
            
            const url = `${API_BASE}availability.php${params.toString() ? '?' + params.toString() : ''}`;
            const response = await fetchWithTimeout(url, { method: 'GET' });
            
            return parseResponse(response);
        },
        
        async create(availabilityData) {
            const required = ['date', 'time_slot'];
            const missing = required.filter(field => !availabilityData[field]);

            if (missing.length > 0) {
                throw new APIError(`Missing required fields: ${missing.join(', ')}`, 400, 'VALIDATION_ERROR');
            }

            const payload = {
                technician_id: availabilityData.technician_id ?? 0,
                date: availabilityData.date,
                time_slot: availabilityData.time_slot,
                is_available: availabilityData.is_available ?? 1
            };

            const response = await fetchWithTimeout(`${API_BASE}availability.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            return parseResponse(response);
        },
        
        async update(id, updates) {
            if (!id) {
                throw new APIError('Availability ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(`${API_BASE}availability.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, ...updates })
            });
            
            return parseResponse(response);
        },
        
        async delete(id) {
            if (!id) {
                throw new APIError('Availability ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(
                `${API_BASE}availability.php?id=${encodeURIComponent(id)}`,
                { method: 'DELETE' }
            );
            
            return parseResponse(response);
        }
    },

    // Ticket Messages (chat between user and technician)
    ticketMessages: {
        async getByTicket(ticketId) {
            if (!ticketId) {
                throw new APIError('Ticket ID is required', 400, 'VALIDATION_ERROR');
            }
            
            const response = await fetchWithTimeout(
                `${API_BASE}ticket-messages.php?ticket_id=${encodeURIComponent(ticketId)}`,
                { method: 'GET' }
            );
            
            return parseResponse(response);
        },
        
        async send(ticketId, message, senderType = null) {
            if (!ticketId) {
                throw new APIError('Ticket ID is required', 400, 'VALIDATION_ERROR');
            }
            if (!message || !message.trim()) {
                throw new APIError('Message is required', 400, 'VALIDATION_ERROR');
            }
            
            const body = { ticket_id: ticketId, message: message.trim() };
            if (senderType) body.sender_type = senderType;
            
            const response = await fetchWithTimeout(`${API_BASE}ticket-messages.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            
            return parseResponse(response);
        }
    }
};

// Export for module systems or make global
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { db, APIError };
}
