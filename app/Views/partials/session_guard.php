<?php
/**
 * Back to the login page when the site was closed. A signed-in page reached
 * from another page of the site (a link, a form, a redirect, a reload) marks
 * its tab; one opened fresh (a new tab or window, a typed or bookmarked
 * address) has no mark and no referrer from this site, so it signs out.
 * The browser-session cookie and AuthFilter's idle limit cover closing the
 * browser and browsers that restore tabs.
 */
?>
    <style>.glc-signing-out body { visibility: hidden; }</style>
    <script>
    (function () {
        var fromSite = false, marked = false;
        try {
            fromSite = document.referrer !== '' && new URL(document.referrer).origin === location.origin;
            marked = sessionStorage.getItem('glc-open') === '1';
            if (fromSite || marked) {
                sessionStorage.setItem('glc-open', '1');
                return;
            }
        } catch (e) {
            return;   // storage blocked: leave it to the idle limit
        }
        document.documentElement.classList.add('glc-signing-out');
        document.addEventListener('DOMContentLoaded', function () {
            var logout = document.querySelector('form[action$="/logout"]');
            if (logout) {
                logout.submit();
            } else {
                document.documentElement.classList.remove('glc-signing-out');
            }
        });
    })();
    </script>
