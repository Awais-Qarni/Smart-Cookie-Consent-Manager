<?php
// Local test fixture only: simulates a site with common trackers.
add_action( 'wp_head', function () {
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	echo '<link rel="stylesheet" id="test-fonts" href="https://fonts.googleapis.com/css2?family=Roboto&display=swap">' . "\n";
	echo "<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-TEST123\"></script>\n";
	echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-TEST123');document.cookie='_ga=GA1.1.123.456; path=/';window.__gaRan=true;</script>\n";
	echo "<script>!function(f){f.fbq=function(){(f.fbq.q=f.fbq.q||[]).push(arguments)};}(window);fbq('init','123');document.cookie='_fbp=fb.1.123; path=/';window.__fbRan=true;</script>\n";
	echo "<script>window.__necessaryRan=true;document.cookie='test_unknown_cookie=1; path=/';try{localStorage.setItem('test_unknown_storage','1')}catch(e){}</script>\n";
	echo "<script type=\"text/plain\" data-sccm-category=\"functional\">window.__manualFunctionalRan=true;</script>\n";
}, 20 );
add_action( 'wp_footer', function () {
	echo '<div id="video-wrap"><iframe id="yt" width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube video" frameborder="0" allowfullscreen></iframe></div>';
	echo '<p><a id="menu-cookie-link" href="#sccm-preferences">Cookie settings (menu link)</a></p>';
	echo do_shortcode( '[sccm_cookie_settings text="Shortcode settings link"]' );
} );
