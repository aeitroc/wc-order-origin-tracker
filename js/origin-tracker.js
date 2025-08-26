 // public/js/origin-tracker.js

document.addEventListener('DOMContentLoaded', function() {
    // Helper function to set a cookie
    const setCookie = (name, value, days) => {
        let expires = "";
        if (days) {
            const date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        // Set cookie for the entire domain
        document.cookie = name + "=" + (value || "") + expires + "; path=/";
    };

    // Helper function to get a cookie
    const getCookie = (name) => {
        const nameEQ = name + "=";
        const ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    };

    // Only set the origin cookie if it doesn't already exist
    if (!getCookie('wc_order_origin')) {
        let origin = 'Direct'; // Default origin
        const urlParams = new URLSearchParams(window.location.search);
        const referrer = document.referrer;

        // 1. Check for UTM parameters (most specific)
        if (urlParams.has('utm_source')) {
            // You can make this as detailed as you want.
            // For this example, we'll just use the source.
            origin = `UTM: ${urlParams.get('utm_source')}`;
        }
        // 2. Check for a referrer
        else if (referrer) {
            const searchEngines = ['google', 'bing', 'yahoo', 'duckduckgo'];
            const referrerHost = new URL(referrer).hostname;

            // Check if the referrer is a known search engine
            if (searchEngines.some(engine => referrerHost.includes(engine))) {
                origin = 'Organic';
            } else {
                // Otherwise, it's a referral from another site
                origin = `Referral: ${referrerHost}`;
            }
        }
        
        // 3. If no UTM and no referrer, it remains 'Direct'

        // Set the cookie to last for 30 days
        setCookie('wc_order_origin', origin, 30);
    }
});