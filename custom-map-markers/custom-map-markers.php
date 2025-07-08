<?php
/**
 * Plugin Name: Custom Map Markers for ClassiThai
 * Description: Displays classified listings on a Google Map using geolocation meta fields. Includes filters, clustering, and mobile-friendly view.
 * Version: 2.1.0 (Translation Ready)
 * Author: AI + Robbie
 */

add_shortcode('custom_map_only', 'custom_render_map_with_filters_v2');

function custom_render_map_with_filters_v2() {
    // A 'custom-map-text-domain' egyedi azonosító a mi szövegeinknek
    $text_domain = 'custom-map-text-domain';
    ob_start();
    ?>
    <style>
        /* A stílusok változatlanok */
        #filter-panel { background: #f9f9f9; padding: 15px; margin-bottom: 10px; border-radius: 10px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        #filter-panel input, #filter-panel select { padding: 6px; min-width: 140px; }
        #custom-map, #listing-panel { width: 100%; height: 500px; }
        #listing-panel { display: none; overflow: auto; padding: 10px; border: 1px solid #ccc; border-radius: 10px; background: #fff; }
        .listing-item { margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .listing-item img { max-width: 120px; display: inline-block; vertical-align: top; margin-right: 10px; }
        .listing-item .details { display: inline-block; vertical-align: top; max-width: calc(100% - 140px); }
        #extra-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        @media(max-width:768px) { #custom-map, #listing-panel { height: 300px; } #filter-panel input, #filter-panel select { min-width: 100%; } #extra-controls { flex-direction: column; align-items: flex-start; } }
    </style>

    <div id="filter-panel">
        <input type="text" id="search-keyword" placeholder="<?php echo esc_attr_x('Search keyword', 'placeholder', $text_domain); ?>">
        <select id="filter-category">
            <option value=""><?php echo esc_html_x('All Categories', 'dropdown item', $text_domain); ?></option>
            <?php
            $query_posts = get_posts(['post_type' => 'rtcl_listing', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
            $categories_used = [];
            foreach ($query_posts as $pid) {
                $lat = get_post_meta($pid, 'latitude', true);
                $lng = get_post_meta($pid, 'longitude', true);
                if (empty($lat) || empty($lng)) continue;
                $terms = get_the_terms($pid, 'rtcl_category');
                if ($terms && !is_wp_error($terms)) {
                    foreach($terms as $term) {
                        if (!isset($categories_used[$term->term_id])) {
                           $categories_used[$term->term_id] = $term;
                        }
                    }
                }
            }
            foreach ($categories_used as $term) {
                echo '<option value="' . esc_attr($term->term_id) . '">' . esc_html($term->name) . '</option>';
            }
            ?>
        </select>
        <select id="filter-type">
            <option value=""><?php echo esc_html_x('All Types', 'dropdown item', $text_domain); ?></option>
            <?php
            $types = [];
            foreach ($query_posts as $post_id) {
                $type = get_post_meta($post_id, 'ad_type', true);
                if ($type && !in_array($type, $types)) {
                    $types[] = $type;
                }
            }
            foreach ($types as $type) {
                echo '<option value="' . esc_attr($type) . '">' . esc_html(ucwords(str_replace('-', ' ', $type))) . '</option>';
            }
            ?>
        </select>
        <input type="number" id="price-min" placeholder="<?php echo esc_attr_x('Min Price', 'placeholder', $text_domain); ?>">
        <input type="number" id="price-max" placeholder="<?php echo esc_attr_x('Max Price', 'placeholder', $text_domain); ?>">
        <button onclick="applyFilters()"><?php echo esc_html_x('Apply Filters', 'button', $text_domain); ?></button>
        <button onclick="resetFilters()"><?php echo esc_html_x('Reset Filters', 'button', $text_domain); ?></button>
        <div id="extra-controls">
            <div id="results-count"></div>
            <div id="toggle-view">
                <button onclick="toggleMapList()"><?php echo esc_html_x('Toggle Map/List View', 'button', $text_domain); ?></button>
            </div>
        </div>
    </div>

    <div id="custom-map"></div>
    <div id="listing-panel"></div>
    <div id="map-markers" style="display: none;" data-translation-results-found="<?php echo esc_attr_x('%d result(s) found.', 'results count', $text_domain); ?>" data-translation-view-listing="<?php echo esc_attr_x('View', 'button', $text_domain); ?>">
        <?php
        foreach ($query_posts as $post_id) {
            // ... (A PHP logika a markerek generálásához változatlan)
            $lat = get_post_meta($post_id, 'latitude', true);
            $lng = get_post_meta($post_id, 'longitude', true);
            if (empty($lat) || empty($lng)) continue;
            $address = get_post_meta($post_id, '_rtcl_address', true);
            $price = get_post_meta($post_id, 'price', true);
            $thumb = get_the_post_thumbnail_url($post_id, 'thumbnail');
            $type = get_post_meta($post_id, 'ad_type', true);
            $terms = get_the_terms($post_id, 'rtcl_category');
            $cat_name = ($terms && !is_wp_error($terms)) ? $terms[0]->name : '';
            $cat_id = ($terms && !is_wp_error($terms)) ? $terms[0]->term_id : '';
            ?>
            <div class="marker"
                 data-latitude="<?php echo esc_attr($lat); ?>"
                 data-longitude="<?php echo esc_attr($lng); ?>"
                 data-address="<?php echo esc_attr($address); ?>"
                 data-price="<?php echo esc_attr($price); ?>"
                 data-image="<?php echo esc_url($thumb); ?>"
                 data-type="<?php echo esc_attr($type); ?>"
                 data-category="<?php echo esc_html($cat_name); ?>"
                 data-category-id="<?php echo esc_attr($cat_id); ?>"
                 data-title="<?php echo esc_attr(get_the_title($post_id)); ?>"
                 data-link="<?php echo get_permalink($post_id); ?>">
            </div>
            <?php
        }
        ?>
    </div>

    <script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>
    <script>
    // A JavaScript kód szinte teljesen változatlan, csak a szövegeket veszi máshonnan
    let map; let defaultBounds; let bounds; let markers = []; let markerCluster; let isListView = false;
    function initCustomMap() {
        map = new google.maps.Map(document.getElementById("custom-map"), { zoom: 7, center: { lat: 13.7563, lng: 100.5018 } });
        bounds = new google.maps.LatLngBounds();
        const translationStrings = document.getElementById('map-markers').dataset;
        
        document.querySelectorAll(".marker").forEach(el => {
            const lat = parseFloat(el.dataset.latitude);
            const lng = parseFloat(el.dataset.longitude);
            const title = el.dataset.title;
            const price = el.dataset.price;
            const image = el.dataset.image;
            const address = el.dataset.address;
            const type = el.dataset.type;
            const category = el.dataset.category;
            const categoryId = el.dataset.categoryId;
            const link = el.dataset.link;
            
            const viewText = translationStrings.translationViewListing || 'View';
            const content = `<div style='max-width:200px;'><img src='${image}' style='width:100%;'><h4>${title}</h4><p>${address}</p><strong>${price} THB</strong><br><a href='${link}' target='_blank'>${viewText}</a></div>`;
            const marker = new google.maps.Marker({ position: { lat, lng }, map, title, customInfo: { title, price, type, category, categoryId, image, address, link } });
            const infowindow = new google.maps.InfoWindow({ content });
            marker.addListener("click", () => infowindow.open(map, marker));
            markers.push(marker);
            bounds.extend(marker.getPosition());
        });
        map.fitBounds(bounds);
        defaultBounds = bounds;
        markerCluster = new markerClusterer.MarkerClusterer({ map, markers });
        applyFilters();
    }
    window.addEventListener('load', function() { if (typeof initCustomMap === 'function') { initCustomMap(); } });
    function toggleMapList() { const mapDiv = document.getElementById('custom-map'); const listDiv = document.getElementById('listing-panel'); isListView = !isListView; mapDiv.style.display = isListView ? 'none' : 'block'; listDiv.style.display = isListView ? 'block' : 'none'; applyFilters(); }
    function resetFilters() { document.getElementById('search-keyword').value = ''; document.getElementById('filter-category').value = ''; document.getElementById('filter-type').value = ''; document.getElementById('price-min').value = ''; document.getElementById('price-max').value = ''; map.fitBounds(defaultBounds); applyFilters(); }
    function updateResultsCount(count) { 
        const resultsText = (document.getElementById('map-markers').dataset.translationResultsFound || '%d result(s) found.').replace('%d', count);
        document.getElementById('results-count').textContent = resultsText;
    }
    function applyFilters() {
        const keyword = document.getElementById('search-keyword').value.toLowerCase();
        const category = document.getElementById('filter-category').value;
        const type = document.getElementById('filter-type').value.toLowerCase();
        const min = parseFloat(document.getElementById('price-min').value);
        const max = parseFloat(document.getElementById('price-max').value);
        if (markerCluster) markerCluster.clearMarkers();
        let visibleMarkers = [];
        let visibleCount = 0;
        let listingHTML = '';
        let tempBounds = new google.maps.LatLngBounds();
        const viewText = (document.getElementById('map-markers').dataset.translationViewListing || 'View');

        markers.forEach(marker => {
            const info = marker.customInfo;
            const title = info.title.toLowerCase();
            const catId = info.categoryId || '';
            const typ = (info.type || '').toLowerCase();
            const price = parseFloat(info.price || 0);
            const visible = (!keyword || title.includes(keyword)) && (!category || catId === category) && (!type || typ === type) && (!min || price >= min) && (!max || price <= max);
            marker.setVisible(visible);
            if (visible) {
                visibleMarkers.push(marker);
                visibleCount++;
                tempBounds.extend(marker.getPosition());
                listingHTML += `<div class='listing-item'><img src='${info.image}'><div class='details'><h4><a href='${info.link}' target='_blank'>${info.title}</a></h4><p>${info.address}</p><strong>${info.price} THB</strong></div></div>`;
            }
        });
        if (visibleMarkers.length > 0 && !isListView) map.fitBounds(tempBounds);
        document.getElementById('listing-panel').innerHTML = listingHTML;
        markerCluster = new markerClusterer.MarkerClusterer({ map, markers: visibleMarkers });
        updateResultsCount(visibleCount);
    }
    </script>
    <?php
    return ob_get_clean();
}