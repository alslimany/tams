import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
window.axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');

if (csrfTokenMeta) {
	window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfTokenMeta.getAttribute('content');
}

window.axios.interceptors.response.use(
	(response) => response,
	async (error) => {
		const status = error?.response?.status;
		const originalRequest = error?.config;
		const method = (originalRequest?.method || '').toLowerCase();
		const isWriteRequest = ['post', 'put', 'patch', 'delete'].includes(method);
		const isCsrfCookieRequest = String(originalRequest?.url || '').includes('/sanctum/csrf-cookie');

		if (status === 419 && originalRequest && isWriteRequest && !originalRequest.__csrfRetried && !isCsrfCookieRequest) {
			originalRequest.__csrfRetried = true;

			try {
				await window.axios.get('/sanctum/csrf-cookie');

				return window.axios(originalRequest);
			} catch (_csrfRefreshError) {
				// Continue and reject the original error below.
			}
		}

		return Promise.reject(error);
	},
);
