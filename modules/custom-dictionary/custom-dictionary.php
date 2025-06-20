<?php
/**
 * Modulo Dizionario Personalizzato per Dynamic Page Translator
 * File: modules/custom-dictionary/custom-dictionary.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class DPT_Custom_Dictionary_Module {
    
    private $dictionary = array();
    private $dictionary_cache = array();
    private $module_path;
    private $module_url;
    
    public function __construct() {
        $this->module_path = DPT_PLUGIN_PATH . 'modules/custom-dictionary/';
        $this->module_url = DPT_PLUGIN_URL . 'modules/custom-dictionary/';
        
        $this->load_dictionary();
        $this->init_hooks();
        $this->register_module();
    }
    
    /**
     * Inizializza hook
     */
    private function init_hooks() {
        // Hook traduzione - priorità alta per essere processato prima della traduzione API
        add_filter('dpt_prepare_content', array($this, 'apply_dictionary'), 5);
        add_filter('dpt_translation_result', array($this, 'restore_dictionary'), 999, 2);
        
        // Admin interface
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_page'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            
            // AJAX handlers
            add_action('wp_ajax_dpt_save_dictionary', array($this, 'ajax_save_dictionary'));
            add_action('wp_ajax_dpt_test_dictionary_rule', array($this, 'ajax_test_dictionary_rule'));
            add_action('wp_ajax_dpt_import_dictionary', array($this, 'ajax_import_dictionary'));
            add_action('wp_ajax_dpt_export_dictionary', array($this, 'ajax_export_dictionary'));
            add_action('wp_ajax_dpt_search_dictionary', array($this, 'ajax_search_dictionary'));
        }
        
        // Frontend check ottimizzato
        add_filter('dpt_fast_dictionary_check', array($this, 'fast_dictionary_check'), 10, 3);
        
        // Hook per cache warming
        add_action('dpt_warm_dictionary_cache', array($this, 'warm_dictionary_cache'));
    }
    
    /**
     * Registra il modulo
     */
    private function register_module() {
        $plugin = DynamicPageTranslator::get_instance();
        $plugin->register_module('custom_dictionary', $this);
        
        // Informazioni modulo
        add_filter('dpt_module_info_custom_dictionary', function() {
            return array(
                'name' => __('Dizionario Personalizzato', 'dynamic-translator'),
                'description' => __('Gestisce traduzioni manuali e esclusioni dal processo di traduzione automatica', 'dynamic-translator'),
                'version' => '1.0.0',
                'author' => 'Dynamic Translator',
                'type' => 'translation_enhancement',
                'settings_url' => admin_url('admin.php?page=dpt-dictionary'),
                'icon' => $this->module_url . 'assets/dictionary-icon.svg'
            );
        });
    }
    
    /**
     * Carica dizionario dal database
     */
    private function load_dictionary() {
        $this->dictionary = get_option('dpt_custom_dictionary', array(
            'excluded_words' => array(),
            'exact_translations' => array(),
            'partial_replacements' => array(),
            'wildcard_patterns' => array(),
            'language_specific' => array(),
            'last_updated' => time(),
            'version' => '1.0'
        ));
        
        // Prepara cache ottimizzata
        $this->build_optimized_cache();
    }
    
    /**
     * Costruisce cache ottimizzata per performance
     */
    private function build_optimized_cache() {
        $this->dictionary_cache = array();
        
        // Cache per esclusioni (array flip per O(1) lookup)
        if (!empty($this->dictionary['excluded_words'])) {
            $this->dictionary_cache['excluded'] = array_flip(
                array_map('strtolower', $this->dictionary['excluded_words'])
            );
        }
        
        // Cache per traduzioni esatte (normalizzate per case-insensitive)
        if (!empty($this->dictionary['exact_translations'])) {
            $this->dictionary_cache['exact'] = array();
            foreach ($this->dictionary['exact_translations'] as $lang_pair => $translations) {
                $this->dictionary_cache['exact'][$lang_pair] = array();
                foreach ($translations as $original => $translation) {
                    $this->dictionary_cache['exact'][$lang_pair][strtolower($original)] = $translation;
                }
            }
        }
        
        // Cache per pattern compilati
        if (!empty($this->dictionary['wildcard_patterns'])) {
            $this->dictionary_cache['patterns'] = array();
            foreach ($this->dictionary['wildcard_patterns'] as $lang_pair => $patterns) {
                $this->dictionary_cache['patterns'][$lang_pair] = array();
                foreach ($patterns as $pattern => $replacement) {
                    $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/i';
                    $this->dictionary_cache['patterns'][$lang_pair][$regex] = $replacement;
                }
            }
        }
    }
    
    /**
     * Applica dizionario pre-traduzione
     */
    public function apply_dictionary($content) {
        // Marca parole escluse con placeholder
        $content = $this->mark_excluded_words($content);
        
        // Applica sostituzioni parziali
        $content = $this->apply_partial_replacements($content);
        
        return $content;
    }
    
    /**
     * Ripristina dizionario post-traduzione
     */
    public function restore_dictionary($translation, $original) {
        // Ripristina parole escluse
        $translation = $this->restore_excluded_words($translation);
        
        return $translation;
    }
    
    /**
     * Check dizionario veloce (per performance manager)
     */
    public function fast_dictionary_check($result, $content, $lang_pair) {
        if ($result !== false) {
            return $result; // Già trovato
        }
        
        // Check traduzione esatta
        $content_lower = strtolower(trim($content));
        if (isset($this->dictionary_cache['exact'][$lang_pair][$content_lower])) {
            return $this->dictionary_cache['exact'][$lang_pair][$content_lower];
        }
        
        // Check pattern wildcard
        if (isset($this->dictionary_cache['patterns'][$lang_pair])) {
            foreach ($this->dictionary_cache['patterns'][$lang_pair] as $regex => $replacement) {
                if (preg_match($regex, $content)) {
                    return preg_replace($regex, $replacement, $content);
                }
            }
        }
        
        return false;
    }
    
    /**
     * Marca parole escluse con placeholder
     */
    private function mark_excluded_words($content) {
        if (empty($this->dictionary_cache['excluded'])) {
            return $content;
        }
        
        // Usa regex per parole intere per evitare sostituzioni parziali
        $words = explode(' ', $content);
        $modified_words = array();
        
        foreach ($words as $word) {
            $clean_word = strtolower(preg_replace('/[^\w]/', '', $word));
            if (isset($this->dictionary_cache['excluded'][$clean_word])) {
                $encoded = base64_encode($word);
                $modified_words[] = "[[DPT_PRESERVE:{$encoded}]]";
            } else {
                $modified_words[] = $word;
            }
        }
        
        return implode(' ', $modified_words);
    }
    
    /**
     * Ripristina parole escluse
     */
    private function restore_excluded_words($content) {
        return preg_replace_callback(
            '/\[\[DPT_PRESERVE:([^\]]+)\]\]/',
            function($matches) {
                return base64_decode($matches[1]);
            },
            $content
        );
    }
    
    /**
     * Applica sostituzioni parziali
     */
    private function apply_partial_replacements($content) {
        if (empty($this->dictionary['partial_replacements'])) {
            return $content;
        }
        
        foreach ($this->dictionary['partial_replacements'] as $search => $replace) {
            $content = str_ireplace($search, $replace, $content);
        }
        
        return $content;
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'dpt-dictionary') === false) {
            return;
        }
        
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('jquery-ui-autocomplete');
        
        wp_enqueue_script(
            'dpt-dictionary-admin',
            $this->module_url . 'assets/dictionary-admin.js',
            array('jquery', 'jquery-ui-sortable', 'jquery-ui-autocomplete'),
            '1.0.0',
            true
        );
        
        wp_enqueue_style(
            'dpt-dictionary-admin',
            $this->module_url . 'assets/dictionary-admin.css',
            array(),
            '1.0.0'
        );
        
        wp_localize_script('dpt-dictionary-admin', 'dptDictionary', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dpt_dictionary_nonce'),
            'strings' => array(
                'addRule' => __('Aggiungi Regola', 'dynamic-translator'),
                'removeRule' => __('Rimuovi', 'dynamic-translator'),
                'testing' => __('Test in corso...', 'dynamic-translator'),
                'testSuccess' => __('Test riuscito!', 'dynamic-translator'),
                'testFailed' => __('Test fallito:', 'dynamic-translator'),
                'confirmDelete' => __('Sei sicuro di voler eliminare questa regola?', 'dynamic-translator'),
                'saving' => __('Salvataggio in corso...', 'dynamic-translator'),
                'saved' => __('Salvato!', 'dynamic-translator'),
                'exportSuccess' => __('Dizionario esportato con successo', 'dynamic-translator'),
                'importSuccess' => __('Dizionario importato con successo', 'dynamic-translator'),
                'invalidFile' => __('File non valido', 'dynamic-translator')
            ),
            'languages' => $this->get_available_languages()
        ));
    }
    
    /**
     * Aggiunge pagina admin
     */
    public function add_admin_page() {
        add_submenu_page(
            'dynamic-translator',
            __('Dizionario Personalizzato', 'dynamic-translator'),
            __('Dizionario', 'dynamic-translator'),
            'manage_options',
            'dpt-dictionary',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Renderizza pagina admin
     */
    public function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'translations';
        ?>
        <div class="wrap dpt-dictionary-admin">
            <h1>
                <?php _e('Dizionario Personalizzato', 'dynamic-translator'); ?>
                <span class="dpt-version">v1.0</span>
            </h1>
            
            <p class="description">
                <?php _e('Gestisci traduzioni personalizzate e parole da escludere dalla traduzione automatica.', 'dynamic-translator'); ?>
            </p>
            
            <!-- Tabs Navigation -->
            <nav class="nav-tab-wrapper dpt-dictionary-tabs">
                <a href="?page=dpt-dictionary&tab=translations" 
                   class="nav-tab <?php echo $active_tab === 'translations' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Traduzioni Esatte', 'dynamic-translator'); ?>
                </a>
                <a href="?page=dpt-dictionary&tab=exclusions" 
                   class="nav-tab <?php echo $active_tab === 'exclusions' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Esclusioni', 'dynamic-translator'); ?>
                </a>
                <a href="?page=dpt-dictionary&tab=partial" 
                   class="nav-tab <?php echo $active_tab === 'partial' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Sostituzioni Parziali', 'dynamic-translator'); ?>
                </a>
                <a href="?page=dpt-dictionary&tab=patterns" 
                   class="nav-tab <?php echo $active_tab === 'patterns' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Pattern Wildcard', 'dynamic-translator'); ?>
                </a>
                <a href="?page=dpt-dictionary&tab=import-export" 
                   class="nav-tab <?php echo $active_tab === 'import-export' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Import/Export', 'dynamic-translator'); ?>
                </a>
                <a href="?page=dpt-dictionary&tab=statistics" 
                   class="nav-tab <?php echo $active_tab === 'statistics' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Statistiche', 'dynamic-translator'); ?>
                </a>
            </nav>
            
            <!-- Tab Content -->
            <div class="dpt-dictionary-content">
                <?php
                switch ($active_tab) {
                    case 'translations':
                        $this->render_translations_tab();
                        break;
                    case 'exclusions':
                        $this->render_exclusions_tab();
                        break;
                    case 'partial':
                        $this->render_partial_tab();
                        break;
                    case 'patterns':
                        $this->render_patterns_tab();
                        break;
                    case 'import-export':
                        $this->render_import_export_tab();
                        break;
                    case 'statistics':
                        $this->render_statistics_tab();
                        break;
                    default:
                        $this->render_translations_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizza tab traduzioni esatte
     */
    private function render_translations_tab() {
        $enabled_languages = dpt_get_option('enabled_languages', array('en', 'it', 'es', 'fr', 'de'));
        $default_language = dpt_get_option('default_language', 'en');
        ?>
        <div class="dpt-dictionary-section">
            <div class="dpt-section-header">
                <h2><?php _e('Traduzioni Esatte', 'dynamic-translator'); ?></h2>
                <p class="description">
                    <?php _e('Definisci traduzioni personalizzate per testi specifici. Queste traduzioni avranno priorità su quelle automatiche.', 'dynamic-translator'); ?>
                </p>
            </div>
            
            <!-- Language Pair Selector -->
            <div class="dpt-language-selector">
                <label><?php _e('Coppia di lingue:', 'dynamic-translator'); ?></label>
                <select id="translation-source-lang">
                    <?php foreach ($enabled_languages as $lang): ?>
                        <option value="<?php echo esc_attr($lang); ?>" <?php selected($lang, $default_language); ?>>
                            <?php echo esc_html($this->get_language_name($lang)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="arrow">→</span>
                <select id="translation-target-lang">
                    <?php foreach ($enabled_languages as $lang): ?>
                        <?php if ($lang !== $default_language): ?>
                            <option value="<?php echo esc_attr($lang); ?>">
                                <?php echo esc_html($this->get_language_name($lang)); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="load-translations" class="button">
                    <?php _e('Carica Traduzioni', 'dynamic-translator'); ?>
                </button>
            </div>
            
            <!-- Translations List -->
            <div class="dpt-translations-container">
                <div class="dpt-translations-header">
                    <div class="dpt-search-box">
                        <input type="text" id="search-translations" placeholder="<?php esc_attr_e('Cerca traduzioni...', 'dynamic-translator'); ?>">
                        <button type="button" id="add-translation" class="button button-primary">
                            <?php _e('Aggiungi Traduzione', 'dynamic-translator'); ?>
                        </button>
                    </div>
                </div>
                
                <div class="dpt-translations-list" id="translations-list">
                    <!-- Popolato via AJAX -->
                </div>
                
                <div class="dpt-translations-actions">
                    <button type="button" id="save-translations" class="button button-primary">
                        <?php _e('Salva Traduzioni', 'dynamic-translator'); ?>
                    </button>
                    <button type="button" id="test-all-translations" class="button">
                        <?php _e('Test Tutte', 'dynamic-translator'); ?>
                    </button>
                    <span class="spinner"></span>
                </div>
            </div>
        </div>
        
        <!-- Translation Item Template -->
        <script type="text/template" id="translation-item-template">
            <div class="dpt-translation-item" data-index="{{index}}">
                <div class="dpt-translation-fields">
                    <div class="dpt-field-group">
                        <label><?php _e('Testo Originale:', 'dynamic-translator'); ?></label>
                        <input type="text" class="original-text" value="{{original}}" placeholder="<?php esc_attr_e('Inserisci testo originale...', 'dynamic-translator'); ?>">
                    </div>
                    <div class="dpt-field-group">
                        <label><?php _e('Traduzione:', 'dynamic-translator'); ?></label>
                        <input type="text" class="translation-text" value="{{translation}}" placeholder="<?php esc_attr_e('Inserisci traduzione...', 'dynamic-translator'); ?>">
                    </div>
                    <div class="dpt-translation-actions">
                        <button type="button" class="button test-translation" title="<?php esc_attr_e('Test traduzione', 'dynamic-translator'); ?>">
                            <span class="dashicons dashicons-search"></span>
                        </button>
                        <button type="button" class="button remove-translation" title="<?php esc_attr_e('Rimuovi traduzione', 'dynamic-translator'); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                        <div class="dpt-drag-handle" title="<?php esc_attr_e('Trascina per riordinare', 'dynamic-translator'); ?>">
                            <span class="dashicons dashicons-menu"></span>
                        </div>
                    </div>
                </div>
                <div class="dpt-test-result" style="display: none;"></div>
            </div>
        </script>
        <?php
    }
    
    /**
     * Renderizza tab esclusioni
     */
    private function render_exclusions_tab() {
        $excluded_words = $this->dictionary['excluded_words'];
        ?>
        <div class="dpt-dictionary-section">
            <div class="dpt-section-header">
                <h2><?php _e('Parole da Non Tradurre', 'dynamic-translator'); ?></h2>
                <p class="description">
                    <?php _e('Elenca le parole che non devono mai essere tradotte. Una parola per riga.', 'dynamic-translator'); ?>
                </p>
            </div>
            
            <div class="dpt-exclusions-container">
                <div class="dpt-exclusions-input">
                    <textarea id="excluded-words" rows="15" cols="50" placeholder="<?php esc_attr_e('Una parola per riga...', 'dynamic-translator'); ?>"><?php 
                        echo esc_textarea(implode("\n", $excluded_words)); 
                    ?></textarea>
                    
                    <div class="dpt-exclusions-helper">
                        <h4><?php _e('Suggerimenti:', 'dynamic-translator'); ?></h4>
                        <ul>
                            <li><?php _e('Inserisci una parola per riga', 'dynamic-translator'); ?></li>
                            <li><?php _e('Le parole non sono case-sensitive', 'dynamic-translator'); ?></li>
                            <li><?php _e('Evita spazi prima e dopo le parole', 'dynamic-translator'); ?></li>
                            <li><?php _e('Usa nomi propri, marchi, termini tecnici', 'dynamic-translator'); ?></li>
                        </ul>
                        
                        <div class="dpt-quick-add">
                            <h4><?php _e('Aggiunte Rapide:', 'dynamic-translator'); ?></h4>
                            <button type="button" class="button" data-words="WordPress,PHP,JavaScript,HTML,CSS">
                                <?php _e('Termini Web', 'dynamic-translator'); ?>
                            </button>
                            <button type="button" class="button" data-words="Google,Facebook,Twitter,Instagram,YouTube">
                                <?php _e('Social Media', 'dynamic-translator'); ?>
                            </button>
                            <button type="button" class="button" data-words="API,URL,SEO,CMS,SSL">
                                <?php _e('Acronimi Tecnici', 'dynamic-translator'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="dpt-exclusions-preview">
                    <h4><?php _e('Anteprima Esclusioni:', 'dynamic-translator'); ?></h4>
                    <div id="exclusions-preview" class="dpt-preview-box">
                        <!-- Popolato via JS -->
                    </div>
                    
                    <div class="dpt-exclusions-stats">
                        <p><strong><?php _e('Totale parole:', 'dynamic-translator'); ?></strong> <span id="total-excluded">0</span></p>
                        <p><strong><?php _e('Parole duplicate:', 'dynamic-translator'); ?></strong> <span id="duplicate-words">0</span></p>
                    </div>
                </div>
            </div>
            
            <div class="dpt-exclusions-actions">
                <button type="button" id="save-exclusions" class="button button-primary">
                    <?php _e('Salva Esclusioni', 'dynamic-translator'); ?>
                </button>
                <button type="button" id="test-exclusions" class="button">
                    <?php _e('Test Esclusioni', 'dynamic-translator'); ?>
                </button>
                <button type="button" id="clear-exclusions" class="button button-secondary">
                    <?php _e('Pulisci Tutto', 'dynamic-translator'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizza tab sostituzioni parziali
     */
    private function render_partial_tab() {
        ?>
        <div class="dpt-dictionary-section">
            <div class="dpt-section-header">
                <h2><?php _e('Sostituzioni Parziali', 'dynamic-translator'); ?></h2>
                <p class="description">
                    <?php _e('Definisci sostituzioni che si applicano a parti del testo prima della traduzione.', 'dynamic-translator'); ?>
                </p>
            </div>
            
            <div class="dpt-partial-container">
                <div class="dpt-partial-list" id="partial-replacements-list">
                    <!-- Popolato via JS -->
                </div>
                
                <button type="button" id="add-partial-replacement" class="button button-primary">
                    <?php _e('Aggiungi Sostituzione', 'dynamic-translator'); ?>
                </button>
            </div>
            
            <div class="dpt-partial-actions">
                <button type="button" id="save-partial-replacements" class="button button-primary">
                    <?php _e('Salva Sostituzioni', 'dynamic-translator'); ?>
                </button>
                <button type="button" id="test-partial-replacements" class="button">
                    <?php _e('Test con Testo di Esempio', 'dynamic-translator'); ?>
                </button>
            </div>
        </div>
        
        <!-- Partial Replacement Template -->
        <script type="text/template" id="partial-replacement-template">
            <div class="dpt-partial-item" data-index="{{index}}">
                <div class="dpt-partial-fields">
                    <input type="text" class="search-text" value="{{search}}" placeholder="<?php esc_attr_e('Testo da cercare...', 'dynamic-translator'); ?>">
                    <span class="arrow">→</span>
                    <input type="text" class="replace-text" value="{{replace}}" placeholder="<?php esc_attr_e('Testo sostituto...', 'dynamic-translator'); ?>">
                    <div class="dpt-partial-options">
                        <label>
                            <input type="checkbox" class="case-sensitive" {{checked}}>
                            <?php _e('Case sensitive', 'dynamic-translator'); ?>
                        </label>
                    </div>
                    <button type="button" class="button remove-partial" title="<?php esc_attr_e('Rimuovi', 'dynamic-translator'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            </div>
        </script>
        <?php
    }
    
    /**
     * Renderizza tab pattern wildcard
     */
    private function render_patterns_tab() {
        ?>
        <div class="dpt-dictionary-section">
            <div class="dpt-section-header">
                <h2><?php _e('Pattern Wildcard', 'dynamic-translator'); ?></h2>
                <p class="description">
                    <?php _e('Crea pattern con wildcard (*) per traduzioni flessibili. Utile per numeri, date, o pattern ricorrenti.', 'dynamic-translator'); ?>
                </p>
            </div>
            
            <div class="dpt-patterns-help">
                <h4><?php _e('Esempi di Pattern:', 'dynamic-translator'); ?></h4>
                <ul class="dpt-examples-list">
                    <li><code>Version *</code> → <code>Versione *</code> <em>(traduce "Version 1.0" in "Versione 1.0")</em></li>
                    <li><code>Page * of *</code> → <code>Pagina * di *</code> <em>(traduce "Page 1 of 10" in "Pagina 1 di 10")</em></li>
                    <li><code>* items in cart</code> → <code>* articoli nel carrello</code></li>
                    <li><code>Copyright * Company</code> → <code>Copyright * Azienda</code></li>
                </ul>
            </div>
            
            <!-- Language Pair Selector for Patterns -->
            <div class="dpt-language-selector">
                <label><?php _e('Coppia di lingue:', 'dynamic-translator'); ?></label>
                <select id="pattern-source-lang">
                    <?php 
                    $enabled_languages = dpt_get_option('enabled_languages', array('en', 'it', 'es', 'fr', 'de'));
                    foreach ($enabled_languages as $lang): ?>
                        <option value="<?php echo esc_attr($lang); ?>">
                            <?php echo esc_html($this->get_language_name($lang)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="arrow">→</span>
                <select id="pattern-target-lang">
                    <?php foreach ($enabled_languages as $lang): ?>
                        <option value="<?php echo esc_attr($lang); ?>">
                            <?php echo esc_html($this->get_language_name($lang)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="load-patterns" class="button">
                    <?php _e('Carica Pattern', 'dynamic-translator'); ?>
                </button>
            </div>
            
            <div class="dpt-patterns-container">
                <div class="dpt-patterns-list" id="wildcard-patterns-list">
                    <!-- Popolato via JS -->
                </div>
                
                <button type="button" id="add-wildcard-pattern" class="button button-primary">
                    <?php _e('Aggiungi Pattern', 'dynamic-translator'); ?>
                </button>
            </div>
            
            <div class="dpt-patterns-tester">
                <h4><?php _e('Test Pattern:', 'dynamic-translator'); ?></h4>
                <input type="text" id="pattern-test-input" placeholder="<?php esc_attr_e('Inserisci testo da testare...', 'dynamic-translator'); ?>">
                <button type="button" id="test-patterns" class="button"><?php _e('Test', 'dynamic-translator'); ?></button>
                <div id="pattern-test-result"></div>
            </div>
            
            <div class="dpt-patterns-actions">
                <button type="button" id="save-patterns" class="button button-primary">
                    <?php _e('Salva Pattern', 'dynamic-translator'); ?>
                </button>
                <button type="button" id="validate-patterns" class="button">
                    <?php _e('Valida Tutti i Pattern', 'dynamic-translator'); ?>
                </button>
            </div>
        </div>
        
        <!-- Pattern Template -->
        <script type="text/template" id="wildcard-pattern-template">
            <div class="dpt-pattern-item" data-index="{{index}}">
                <div class="dpt-pattern-fields">
                    <input type="text" class="pattern-text" value="{{pattern}}" placeholder="<?php esc_attr_e('Pattern con * (es: Version *)...', 'dynamic-translator'); ?>">
                    <span class="arrow">→</span>
                    <input type="text" class="replacement-text" value="{{replacement}}" placeholder="<?php esc_attr_e('Sostituzione con * (es: Versione *)...', 'dynamic-translator'); ?>">
                    <button type="button" class="button test-pattern" title="<?php esc_attr_e('Test pattern', 'dynamic-translator'); ?>">
                        <span class="dashicons dashicons-search"></span>
                    </button>
                    <button type="button" class="button remove-pattern" title="<?php esc_attr_e('Rimuovi', 'dynamic-translator'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
                <div class="dpt-pattern-preview">
                    <small><?php _e('Regex:', 'dynamic-translator'); ?> <code>{{regex}}</code></small>
                </div>
            </div>
        </script>
        <?php
    }
    
    /**
     * Renderizza tab import/export
     */
    private function render_import_export_tab() {
        ?>
        <div class="dpt-dictionary-section">
            <div class="dpt-section-header">
                <h2><?php _e('Import/Export Dizionario', 'dynamic-translator'); ?></h2>
                <p class="description">
                    <?php _e('Importa ed esporta le configurazioni del dizionario per backup o condivisione.', 'dynamic-translator'); ?>
                </p>
            </div>
            
            <div class="dpt-import-export-grid">
                <!-- Export Section -->
                <div class="dpt-export-section">
                    <h3><?php _e('Esporta Dizionario', 'dynamic-translator'); ?></h3>
                    
                    <div class="dpt-export-options">
                        <h4><?php _e('Scegli cosa esportare:', 'dynamic-translator'); ?></h4>
                        <label>
                            <input type="checkbox" id="export-translations" checked>
                            <?php _e('Traduzioni Esatte', 'dynamic-translator'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="export-exclusions" checked>
                            <?php _e('Parole Escluse', 'dynamic-translator'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="export-partial" checked>
                            <?php _e('Sostituzioni Parziali', 'dynamic-translator'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="export-patterns" checked>
                            <?php _e('Pattern Wildcard', 'dynamic-translator'); ?>
                        </label>
                    </div>
                    
                    <div class="dpt-export-format">
                        <h4><?php _e('Formato:', 'dynamic-translator'); ?></h4>
                        <label>
                            <input type="radio" name="export-format" value="json" checked>
                            <?php _e('JSON (Raccomandato)', 'dynamic-translator'); ?>
                        </label>
                        <label>
                            <input type="radio" name="export-format" value="csv">
                            <?php _e('CSV (Solo traduzioni)', 'dynamic-translator'); ?>
                        </label>
                        <label>
                            <input type="radio" name="export-format" value="xml">
                            <?php _e('XML', 'dynamic-translator'); ?>
                        </label>
                    </div>
                    
                    <button type="button" id="export-dictionary" class="button button-primary">
                        <?php _e('Esporta Dizionario', 'dynamic-translator'); ?>
                    </button>
                    
                    <div class="dpt-export-stats">
                        <p><?php _e('Ultimo export:', 'dynamic-translator'); ?> <span id="last-export-date">-</span></p>
                        <p><?php _e('Dimensione attuale:', 'dynamic-translator'); ?> <span id="dictionary-size">-</span></p>
                    </div>
                </div>
                
                <!-- Import Section -->
                <div class="dpt-import-section">
                    <h3><?php _e('Importa Dizionario', 'dynamic-translator'); ?></h3>
                    
                    <div class="dpt-import-uploader">
                        <div class="dpt-upload-area" id="dictionary-upload-area">
                            <div class="dpt-upload-icon">📁</div>
                            <div class="dpt-upload-text"><?php _e('Trascina il file qui o clicca per selezionare', 'dynamic-translator'); ?></div>
                            <div class="dpt-upload-hint"><?php _e('Formati supportati: JSON, CSV, XML', 'dynamic-translator'); ?></div>
                            <input type="file" id="import-dictionary-file" accept=".json,.csv,.xml" style="display: none;">
                        </div>
                    </div>
                    
                    <div class="dpt-import-options" style="display: none;" id="import-options">
                        <h4><?php _e('Opzioni Import:', 'dynamic-translator'); ?></h4>
                        <label>
                            <input type="checkbox" id="merge-import" checked>
                            <?php _e('Unisci con dizionario esistente', 'dynamic-translator'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="overwrite-duplicates">
                            <?php _e('Sovrascrivi duplicati', 'dynamic-translator'); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="backup-before-import" checked>
                            <?php _e('Crea backup prima dell\'import', 'dynamic-translator'); ?>
                        </label>
                    </div>
                    
                    <div class="dpt-import-preview" style="display: none;" id="import-preview">
                        <h4><?php _e('Anteprima Import:', 'dynamic-translator'); ?></h4>
                        <div id="import-preview-content"></div>
                    </div>
                    
                    <button type="button" id="import-dictionary" class="button button-primary" style="display: none;">
                        <?php _e('Importa Dizionario', 'dynamic-translator'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Quick Templates -->
            <div class="dpt-templates-section">
                <h3><?php _e('Template Rapidi', 'dynamic-translator'); ?></h3>
                <p class="description"><?php _e('Carica template predefiniti per iniziare velocemente.', 'dynamic-translator'); ?></p>
                
                <div class="dpt-templates-grid">
                    <div class="dpt-template-card" data-template="ecommerce">
                        <h4><?php _e('E-commerce', 'dynamic-translator'); ?></h4>
                        <p><?php _e('Termini comuni per negozi online', 'dynamic-translator'); ?></p>
                        <button type="button" class="button load-template"><?php _e('Carica', 'dynamic-translator'); ?></button>
                    </div>
                    <div class="dpt-template-card" data-template="blog">
                        <h4><?php _e('Blog', 'dynamic-translator'); ?></h4>
                        <p><?php _e('Termini per blog e contenuti', 'dynamic-translator'); ?></p>
                        <button type="button" class="button load-template"><?php _e('Carica', 'dynamic-translator'); ?></button>
                    </div>
                    <div class="dpt-template-card" data-template="corporate">
                        <h4><?php _e('Corporate', 'dynamic-translator'); ?></h4>
                        <p><?php _e('Terminologia aziendale', 'dynamic-translator'); ?></p>
                        <button type="button" class="button load-template"><?php _e('Carica', 'dynamic-translator'); ?></button>
                    </div>
                    <div class="dpt-template-card" data-template="tech">
                        <h4><?php _e('Tecnico', 'dynamic-translator'); ?></h4>
                        <p><?php _e('Termini tecnici e informatici', 'dynamic-translator'); ?></p>
                        <button type="button" class="button load-template"><?php _e('Carica', 'dynamic-translator'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizza tab statistiche
     */
    private function render_statistics_tab() {
        $stats = $this->get_dictionary_statistics();
        ?>
        <div class="dpt-dictionary-section">
            <div class="dpt-section-header">
                <h2><?php _e('Statistiche Dizionario', 'dynamic-translator'); ?></h2>
                <p class="description">
                    <?php _e('Statistiche sull\'utilizzo e l\'efficacia del dizionario personalizzato.', 'dynamic-translator'); ?>
                </p>
            </div>
            
            <!-- Statistics Cards -->
            <div class="dpt-stats-grid">
                <div class="dpt-stat-card">
                    <div class="dpt-stat-number"><?php echo number_format($stats['total_translations']); ?></div>
                    <div class="dpt-stat-label"><?php _e('Traduzioni Personalizzate', 'dynamic-translator'); ?></div>
                </div>
                <div class="dpt-stat-card">
                    <div class="dpt-stat-number"><?php echo number_format($stats['excluded_words']); ?></div>
                    <div class="dpt-stat-label"><?php _e('Parole Escluse', 'dynamic-translator'); ?></div>
                </div>
                <div class="dpt-stat-card">
                    <div class="dpt-stat-number"><?php echo number_format($stats['partial_replacements']); ?></div>
                    <div class="dpt-stat-label"><?php _e('Sostituzioni Parziali', 'dynamic-translator'); ?></div>
                </div>
                <div class="dpt-stat-card">
                    <div class="dpt-stat-number"><?php echo number_format($stats['wildcard_patterns']); ?></div>
                    <div class="dpt-stat-label"><?php _e('Pattern Wildcard', 'dynamic-translator'); ?></div>
                </div>
                <div class="dpt-stat-card">
                    <div class="dpt-stat-number"><?php echo number_format($stats['language_pairs']); ?></div>
                    <div class="dpt-stat-label"><?php _e('Coppie di Lingue', 'dynamic-translator'); ?></div>
                </div>
                <div class="dpt-stat-card">
                    <div class="dpt-stat-number"><?php echo $stats['usage_percentage']; ?>%</div>
                    <div class="dpt-stat-label"><?php _e('Utilizzo del Dizionario', 'dynamic-translator'); ?></div>
                </div>
            </div>
            
            <!-- Usage Charts -->
            <div class="dpt-charts-section">
                <div class="dpt-chart-container">
                    <h3><?php _e('Utilizzo per Lingua', 'dynamic-translator'); ?></h3>
                    <canvas id="language-usage-chart" width="400" height="200"></canvas>
                </div>
                
                <div class="dpt-chart-container">
                    <h3><?php _e('Tipologie di Regole', 'dynamic-translator'); ?></h3>
                    <canvas id="rules-types-chart" width="400" height="200"></canvas>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="dpt-recent-activity">
                <h3><?php _e('Attività Recente', 'dynamic-translator'); ?></h3>
                <div class="dpt-activity-list">
                    <?php foreach ($stats['recent_activity'] as $activity): ?>
                        <div class="dpt-activity-item">
                            <span class="dpt-activity-time"><?php echo esc_html($activity['time']); ?></span>
                            <span class="dpt-activity-action"><?php echo esc_html($activity['action']); ?></span>
                            <span class="dpt-activity-details"><?php echo esc_html($activity['details']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Performance Impact -->
            <div class="dpt-performance-section">
                <h3><?php _e('Impatto sulle Performance', 'dynamic-translator'); ?></h3>
                <div class="dpt-performance-metrics">
                    <div class="dpt-metric">
                        <label><?php _e('Cache Hit Rate:', 'dynamic-translator'); ?></label>
                        <span><?php echo $stats['cache_hit_rate']; ?>%</span>
                    </div>
                    <div class="dpt-metric">
                        <label><?php _e('Tempo Medio Lookup:', 'dynamic-translator'); ?></label>
                        <span><?php echo $stats['avg_lookup_time']; ?>ms</span>
                    </div>
                    <div class="dpt-metric">
                        <label><?php _e('Traduzioni Evitate:', 'dynamic-translator'); ?></label>
                        <span><?php echo number_format($stats['translations_avoided']); ?></span>
                    </div>
                    <div class="dpt-metric">
                        <label><?php _e('Risparmio Stimato:', 'dynamic-translator'); ?></label>
                        <span>$<?php echo number_format($stats['estimated_savings'], 2); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="dpt-stats-actions">
                <button type="button" id="refresh-stats" class="button">
                    <?php _e('Aggiorna Statistiche', 'dynamic-translator'); ?>
                </button>
                <button type="button" id="export-stats" class="button">
                    <?php _e('Esporta Report', 'dynamic-translator'); ?>
                </button>
                <button type="button" id="reset-stats" class="button button-secondary">
                    <?php _e('Reset Statistiche', 'dynamic-translator'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Salva dizionario
     */
    public function ajax_save_dictionary() {
        check_ajax_referer('dpt_dictionary_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $type = sanitize_text_field($_POST['type']);
        $data = $_POST['data'];
        
        switch ($type) {
            case 'translations':
                $this->save_exact_translations($data);
                break;
            case 'exclusions':
                $this->save_exclusions($data);
                break;
            case 'partial':
                $this->save_partial_replacements($data);
                break;
            case 'patterns':
                $this->save_wildcard_patterns($data);
                break;
        }
        
        // Ricostruisci cache
        $this->build_optimized_cache();
        
        wp_send_json_success(__('Dizionario salvato con successo', 'dynamic-translator'));
    }
    
    /**
     * AJAX: Test regola dizionario
     */
    public function ajax_test_dictionary_rule() {
        check_ajax_referer('dpt_dictionary_nonce', 'nonce');
        
        $type = sanitize_text_field($_POST['type']);
        $test_text = sanitize_textarea_field($_POST['test_text']);
        $rule_data = $_POST['rule_data'];
        
        $result = $this->test_rule($type, $test_text, $rule_data);
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Import dizionario
     */
    public function ajax_import_dictionary() {
        check_ajax_referer('dpt_dictionary_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!isset($_FILES['dictionary_file'])) {
            wp_send_json_error('No file uploaded');
        }
        
        $file = $_FILES['dictionary_file'];
        $file_content = file_get_contents($file['tmp_name']);
        $format = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        $imported_data = $this->parse_import_file($file_content, $format);
        
        if (!$imported_data) {
            wp_send_json_error('Invalid file format');
        }
        
        $merge = isset($_POST['merge']) && $_POST['merge'] === 'true';
        $result = $this->import_dictionary_data($imported_data, $merge);
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Export dizionario
     */
    public function ajax_export_dictionary() {
        check_ajax_referer('dpt_dictionary_nonce', 'nonce');
        
        $options = $_POST['options'];
        $format = sanitize_text_field($_POST['format']);
        
        $export_data = $this->prepare_export_data($options);
        $file_content = $this->format_export_data($export_data, $format);
        
        wp_send_json_success(array(
            'content' => $file_content,
            'filename' => 'dpt-dictionary-' . date('Y-m-d') . '.' . $format,
            'format' => $format
        ));
    }
    
    /**
     * Ottieni statistiche dizionario
     */
    private function get_dictionary_statistics() {
        // Implementazione delle statistiche
        $stats = get_option('dpt_dictionary_stats', array());
        
        return array(
            'total_translations' => $this->count_total_translations(),
            'excluded_words' => count($this->dictionary['excluded_words']),
            'partial_replacements' => count($this->dictionary['partial_replacements']),
            'wildcard_patterns' => $this->count_wildcard_patterns(),
            'language_pairs' => $this->count_language_pairs(),
            'usage_percentage' => $this->calculate_usage_percentage(),
            'cache_hit_rate' => $stats['cache_hit_rate'] ?? 0,
            'avg_lookup_time' => $stats['avg_lookup_time'] ?? 0,
            'translations_avoided' => $stats['translations_avoided'] ?? 0,
            'estimated_savings' => $stats['estimated_savings'] ?? 0,
            'recent_activity' => $this->get_recent_activity()
        );
    }
    
    /**
     * Utility functions
     */
    private function get_available_languages() {
        return dpt_get_option('enabled_languages', array('en', 'it', 'es', 'fr', 'de'));
    }
    
    private function get_language_name($lang_code) {
        $names = array(
            'en' => 'English',
            'it' => 'Italiano', 
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'pt' => 'Português',
            'ru' => 'Русский',
            'zh' => '中文',
            'ja' => '日本語',
            'ar' => 'العربية'
        );
        
        return $names[$lang_code] ?? $lang_code;
    }
    
    private function count_total_translations() {
        $count = 0;
        if (isset($this->dictionary['exact_translations'])) {
            foreach ($this->dictionary['exact_translations'] as $translations) {
                $count += count($translations);
            }
        }
        return $count;
    }
    
    private function count_wildcard_patterns() {
        $count = 0;
        if (isset($this->dictionary['wildcard_patterns'])) {
            foreach ($this->dictionary['wildcard_patterns'] as $patterns) {
                $count += count($patterns);
            }
        }
        return $count;
    }
    
    private function count_language_pairs() {
        $pairs = array();
        if (isset($this->dictionary['exact_translations'])) {
            $pairs = array_merge($pairs, array_keys($this->dictionary['exact_translations']));
        }
        if (isset($this->dictionary['wildcard_patterns'])) {
            $pairs = array_merge($pairs, array_keys($this->dictionary['wildcard_patterns']));
        }
        return count(array_unique($pairs));
    }
    
    private function calculate_usage_percentage() {
        $total_rules = $this->count_total_translations() + 
                      count($this->dictionary['excluded_words']) + 
                      count($this->dictionary['partial_replacements']) + 
                      $this->count_wildcard_patterns();
        
        $usage_stats = get_option('dpt_dictionary_usage_stats', array('total_hits' => 0, 'total_requests' => 1));
        
        return $usage_stats['total_requests'] > 0 ? 
            round(($usage_stats['total_hits'] / $usage_stats['total_requests']) * 100, 1) : 0;
    }
    
    private function get_recent_activity() {
        return get_option('dpt_dictionary_recent_activity', array());
    }
    
    /**
     * Warm cache dizionario
     */
    public function warm_dictionary_cache() {
        // Implementazione cache warming
        $this->build_optimized_cache();
    }
    
    // ... altre funzioni di supporto per import/export, test, salvataggio, ecc.
    
    private function save_exact_translations($data) {
        // Implementazione salvataggio traduzioni esatte
    }
    
    private function save_exclusions($data) {
        // Implementazione salvataggio esclusioni
    }
    
    private function save_partial_replacements($data) {
        // Implementazione salvataggio sostituzioni parziali
    }
    
    private function save_wildcard_patterns($data) {
        // Implementazione salvataggio pattern wildcard
    }
    
    private function test_rule($type, $test_text, $rule_data) {
        // Implementazione test regole
        return array('result' => $test_text, 'applied' => false);
    }
    
    private function parse_import_file($content, $format) {
        // Implementazione parsing file import
        return json_decode($content, true);
    }
    
    private function import_dictionary_data($data, $merge) {
        // Implementazione import dati
        return array('imported' => 0, 'errors' => 0);
    }
    
    private function prepare_export_data($options) {
        // Implementazione preparazione dati export
        return $this->dictionary;
    }
    
    private function format_export_data($data, $format) {
        // Implementazione formattazione export
        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);
            case 'csv':
                // Implementazione CSV
                return '';
            case 'xml':
                // Implementazione XML
                return '';
            default:
                return json_encode($data);
        }
    }
}

// Inizializza il modulo
new DPT_Custom_Dictionary_Module();