// public/js/api-client.js
const API_BASE_URL = '/api'; // Adjust if your API is at a different base path

class ApiClient {
    async request(endpoint, method = 'GET', data = null) {
        const url = `${API_BASE_URL}/${endpoint}`;
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
            },
            // Include credentials (cookies for session) with requests
            credentials: 'include'
        };

        if (data) {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(url, options);

            // If unauthorized, redirect to login page
            if (response.status === 401) {
                console.warn('Unauthorized API access, redirecting to login...');
                window.location.href = '/login.html'; // Or your login route
                return; // Prevent further processing
            }

            const responseData = await response.json();

            if (!response.ok) {
                // Server returned an error status (4xx, 5xx)
                const error = new Error(responseData.error || `API error: ${response.status}`);
                error.status = response.status;
                error.data = responseData;
                throw error;
            }

            return responseData;
        } catch (error) {
            console.error(`API request failed for ${method} ${endpoint}:`, error);
            // Re-throw to allow specific handling in the calling code
            throw error;
        }
    }

    get(endpoint) {
        return this.request(endpoint, 'GET');
    }

    post(endpoint, data) {
        return this.request(endpoint, 'POST', data);
    }

    put(endpoint, data) {
        return this.request(endpoint, 'PUT', data);
    }

    delete(endpoint) {
        return this.request(endpoint, 'DELETE');
    }
}

const apiClient = new ApiClient();