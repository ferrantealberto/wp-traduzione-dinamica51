/**
 * JavaScript Admin per Modulo Dizionario Personalizzato
 * File: modules/custom-dictionary/assets/dictionary-admin.js
 */

(function($) {
    'use strict';
    
    let dictionaryData = {};
    let currentLangPair = '';
    let isDirty = false;
    
    /**
     * Inizializzazione
     */
    function init() {
        setupTabs();
        setupLanguageSelectors();
        setupTranslationsTab();
        setupExclusionsTab();
        setupPartialTab();
        setupPatternsTab();
        setupImportExportTab();
        setupStatisticsTab();
        setupAutoSave();
        setupKeyboardShortcuts();
        
        // Event listener globali
        $(document).on('input change', 'input, textarea, select', markDirty);
        $(window).on('beforeunload', checkUnsavedChanges);
        
        console.log('DPT Dictionary Admin initialized');
    }
    
    /**
     * Setup tabs navigation
     */
    function setupTabs() {
        $('.dpt-dictionary-tabs .nav-tab').on('click', function(e) {
            e.preventDefault();
            
            const $tab = $(this);
            const targetTab = $tab.attr('href').split('tab=')[1];
            
            // Update active tab
            $('.dpt-dictionary-tabs .nav-tab').removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');
            
            // Show/hide tab content
            $('.dpt-tab-content').hide();
            $('#dpt-tab-' + targetTab).show();
            
            // Update URL
            if (history.replaceState) {
                const url = new URL(window.location);
                url.searchParams.set('tab', targetTab);
                history.replaceState(null, '', url);
            }
            
            // Trigger tab change event
            $(document).trigger('dpt:tabChanged', targetTab);
        });
    }
    
    /**
     * Setup selettori lingua
     */
    function setupLanguageSelectors() {
        // Translation language selector
        $('#translation-source-lang, #translation-target-lang').on('change', function() {
            updateCurrentLangPair('translations');
        });
        
        // Pattern language selector
        $('#pattern-source-lang, #pattern-target-lang').on('change', function() {
            updateCurrentLangPair('patterns');
        });
        
        // Load translations button
        $('#load-translations').on('click', function() {
            loadTranslations();
        });
        
        // Load patterns button
        $('#load-patterns').on('click', function() {
            loadPatterns();
        });
    }
    
    /**
     * Aggiorna coppia lingua corrente
     */
    function updateCurrentLangPair(type) {
        let sourceSelector, targetSelector;
        
        if (type === 'translations') {
            sourceSelector = '#translation-source-lang';
            targetSelector = '#translation-target-lang';
        } else if (type === 'patterns') {
            sourceSelector = '#pattern-source-lang';
            targetSelector = '#pattern-target-lang';
        }
        
        const sourceLang = $(sourceSelector).val();
        const targetLang = $(targetSelector).val();
        currentLangPair = sourceLang + '_' + targetLang;
        
        console.log('Language pair updated:', currentLangPair);
    }
    
    /**
     * Setup tab traduzioni esatte
     */
    function setupTranslationsTab() {
        // Add translation button
        $('#add-translation').on('click', function() {
            addTranslationItem();
        });
        
        // Save translations button
        $('#save-translations').on('click', function() {
            saveTranslations();
        });
        
        // Test all translations button
        $('#test-all-translations').on('click', function() {
            testAllTranslations();
        });
        
        // Search translations
        $('#search-translations').on('input', function() {
            filterTranslations($(this).val());
        });
        
        // Event delegation per translation items
        $(document).on('click', '.test-translation', function() {
            testSingleTranslation($(this).closest('.dpt-translation-item'));
        });
        
        $(document).on('click', '.remove-translation', function() {
            removeTranslationItem($(this).closest('.dpt-translation-item'));
        });
        
        // Sortable translations
        if ($.fn.sortable) {
            $('#translations-list').sortable({
                handle: '.dpt-drag-handle',
                placeholder: 'dpt-translation-placeholder',
                tolerance: 'pointer',
                update: function() {
                    markDirty();
                }
            });
        }
    }
    
    /**
     * Aggiunge nuovo item traduzione
     */
    function addTranslationItem(original = '', translation = '') {
        const template = $('#translation-item-template').html();
        const index = $('.dpt-translation-item').length;
        
        const html = template
            .replace(/{{index}}/g, index)
            .replace(/{{original}}/g, original)
            .replace(/{{translation}}/g, translation);
        
        $('#translations-list').append(html);
        
        // Focus sul primo campo
        $('#translations-list .dpt-translation-item:last .original-text').focus();
        
        markDirty();
    }
    
    /**
     * Rimuove item traduzione
     */
    function removeTranslationItem($item) {
        if (confirm(dptDictionary.strings.confirmDelete)) {
            $item.fadeOut(300, function() {
                $(this).remove();
                markDirty();
            });
        }
    }
    
    /**
     * Carica traduzioni per coppia lingua
     */
    function loadTranslations() {
        if (!currentLangPair) {
            updateCurrentLangPair('translations');
        }
        
        const $button = $('#load-translations');
        const originalText = $button.text();
        
        $button.prop('disabled', true).text(dptDictionary.strings.loading || 'Caricamento...');
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_load_translations',
                lang_pair: currentLangPair,
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayTranslations(response.data);
                } else {
                    showMessage('error', response.data);
                }
            },
            error: function() {
                showMessage('error', 'Errore durante il caricamento delle traduzioni');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }
    
    /**
     * Visualizza traduzioni caricate
     */
    function displayTranslations(translations) {
        $('#translations-list').empty();
        
        if (translations && Object.keys(translations).length > 0) {
            Object.entries(translations).forEach(([original, translation]) => {
                addTranslationItem(original, translation);
            });
        } else {
            $('#translations-list').html('<p style="padding: 20px; text-align: center; color: #666;">Nessuna traduzione trovata per questa coppia di lingue. Clicca "Aggiungi Traduzione" per iniziare.</p>');
        }
    }
    
    /**
     * Salva traduzioni
     */
    function saveTranslations() {
        const translations = collectTranslations();
        
        const $button = $('#save-translations');
        const originalText = $button.text();
        
        $button.prop('disabled', true).text(dptDictionary.strings.saving || 'Salvando...');
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_save_dictionary',
                type: 'translations',
                lang_pair: currentLangPair,
                data: translations,
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', dptDictionary.strings.saved || 'Salvato!');
                    markClean();
                } else {
                    showMessage('error', response.data);
                }
            },
            error: function() {
                showMessage('error', 'Errore durante il salvataggio');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }
    
    /**
     * Raccoglie traduzioni dal form
     */
    function collectTranslations() {
        const translations = {};
        
        $('.dpt-translation-item').each(function() {
            const $item = $(this);
            const original = $item.find('.original-text').val().trim();
            const translation = $item.find('.translation-text').val().trim();
            
            if (original && translation) {
                translations[original] = translation;
            }
        });
        
        return translations;
    }
    
    /**
     * Test singola traduzione
     */
    function testSingleTranslation($item) {
        const original = $item.find('.original-text').val().trim();
        const translation = $item.find('.translation-text').val().trim();
        
        if (!original || !translation) {
            showTestResult($item, 'error', 'Inserisci sia il testo originale che la traduzione');
            return;
        }
        
        const $result = $item.find('.dpt-test-result');
        $result.removeClass('success error').addClass('loading')
               .html('<span class="spinner is-active"></span> Test in corso...')
               .show();
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_test_dictionary_rule',
                type: 'translation',
                test_text: original,
                rule_data: {
                    original: original,
                    translation: translation,
                    lang_pair: currentLangPair
                },
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    const result = response.data;
                    if (result.applied && result.result === translation) {
                        showTestResult($item, 'success', '✓ Test riuscito: "' + original + '" → "' + result.result + '"');
                    } else {
                        showTestResult($item, 'error', '✗ La traduzione non funziona come previsto');
                    }
                } else {
                    showTestResult($item, 'error', 'Errore durante il test');
                }
            },
            error: function() {
                showTestResult($item, 'error', 'Errore di connessione');
            }
        });
    }
    
    /**
     * Test tutte le traduzioni
     */
    function testAllTranslations() {
        const $items = $('.dpt-translation-item');
        let completed = 0;
        const total = $items.length;
        
        if (total === 0) {
            showMessage('error', 'Nessuna traduzione da testare');
            return;
        }
        
        $items.each(function() {
            const $item = $(this);
            setTimeout(() => {
                testSingleTranslation($item);
                completed++;
                
                if (completed === total) {
                    showMessage('success', `Test completato su ${total} traduzioni`);
                }
            }, completed * 200); // Stagger i test
        });
    }
    
    /**
     * Filtra traduzioni
     */
    function filterTranslations(query) {
        query = query.toLowerCase();
        
        $('.dpt-translation-item').each(function() {
            const $item = $(this);
            const original = $item.find('.original-text').val().toLowerCase();
            const translation = $item.find('.translation-text').val().toLowerCase();
            
            if (original.includes(query) || translation.includes(query)) {
                $item.show();
            } else {
                $item.hide();
            }
        });
    }
    
    /**
     * Setup tab esclusioni
     */
    function setupExclusionsTab() {
        // Save exclusions
        $('#save-exclusions').on('click', function() {
            saveExclusions();
        });
        
        // Test exclusions
        $('#test-exclusions').on('click', function() {
            testExclusions();
        });
        
        // Clear exclusions
        $('#clear-exclusions').on('click', function() {
            if (confirm('Sei sicuro di voler eliminare tutte le esclusioni?')) {
                $('#excluded-words').val('');
                updateExclusionsPreview();
                markDirty();
            }
        });
        
        // Quick add buttons
        $('.dpt-quick-add .button').on('click', function() {
            const words = $(this).data('words');
            if (words) {
                const currentWords = $('#excluded-words').val();
                const newWords = currentWords ? currentWords + '\n' + words : words;
                $('#excluded-words').val(newWords);
                updateExclusionsPreview();
                markDirty();
            }
        });
        
        // Update preview on input
        $('#excluded-words').on('input', function() {
            updateExclusionsPreview();
        });
        
        // Initial preview update
        updateExclusionsPreview();
    }
    
    /**
     * Aggiorna anteprima esclusioni
     */
    function updateExclusionsPreview() {
        const words = $('#excluded-words').val()
            .split('\n')
            .map(word => word.trim())
            .filter(word => word.length > 0);
        
        const uniqueWords = [...new Set(words)];
        const duplicates = words.length - uniqueWords.length;
        
        $('#exclusions-preview').html(uniqueWords.join(', '));
        $('#total-excluded').text(uniqueWords.length);
        $('#duplicate-words').text(duplicates);
        
        if (duplicates > 0) {
            $('#duplicate-words').css('color', '#d63384');
        } else {
            $('#duplicate-words').css('color', '#28a745');
        }
    }
    
    /**
     * Salva esclusioni
     */
    function saveExclusions() {
        const words = $('#excluded-words').val()
            .split('\n')
            .map(word => word.trim())
            .filter(word => word.length > 0);
        
        const uniqueWords = [...new Set(words)];
        
        const $button = $('#save-exclusions');
        const originalText = $button.text();
        
        $button.prop('disabled', true).text(dptDictionary.strings.saving || 'Salvando...');
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_save_dictionary',
                type: 'exclusions',
                data: uniqueWords,
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', `${uniqueWords.length} parole salvate con successo`);
                    markClean();
                } else {
                    showMessage('error', response.data);
                }
            },
            error: function() {
                showMessage('error', 'Errore durante il salvataggio');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }
    
    /**
     * Test esclusioni
     */
    function testExclusions() {
        const testText = prompt('Inserisci un testo per testare le esclusioni:', 'WordPress è fantastico e Google è utile');
        if (!testText) return;
        
        const words = $('#excluded-words').val()
            .split('\n')
            .map(word => word.trim())
            .filter(word => word.length > 0);
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_test_dictionary_rule',
                type: 'exclusions',
                test_text: testText,
                rule_data: { excluded_words: words },
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    const result = response.data;
                    if (result.modified) {
                        showMessage('success', 'Test riuscito! Testo modificato: "' + result.result + '"');
                    } else {
                        showMessage('info', 'Nessuna esclusione applicata al testo di test');
                    }
                } else {
                    showMessage('error', 'Errore durante il test');
                }
            },
            error: function() {
                showMessage('error', 'Errore di connessione');
            }
        });
    }
    
    /**
     * Setup tab sostituzioni parziali
     */
    function setupPartialTab() {
        // Add partial replacement
        $('#add-partial-replacement').on('click', function() {
            addPartialItem();
        });
        
        // Save partial replacements
        $('#save-partial-replacements').on('click', function() {
            savePartialReplacements();
        });
        
        // Test partial replacements
        $('#test-partial-replacements').on('click', function() {
            testPartialReplacements();
        });
        
        // Event delegation
        $(document).on('click', '.remove-partial', function() {
            removePartialItem($(this).closest('.dpt-partial-item'));
        });
        
        // Load existing partial replacements
        loadPartialReplacements();
    }
    
    /**
     * Aggiunge item sostituzione parziale
     */
    function addPartialItem(search = '', replace = '', caseSensitive = false) {
        const template = $('#partial-replacement-template').html();
        const index = $('.dpt-partial-item').length;
        
        const html = template
            .replace(/{{index}}/g, index)
            .replace(/{{search}}/g, search)
            .replace(/{{replace}}/g, replace)
            .replace(/{{checked}}/g, caseSensitive ? 'checked' : '');
        
        $('#partial-replacements-list').append(html);
        markDirty();
    }
    
    /**
     * Rimuove item sostituzione parziale
     */
    function removePartialItem($item) {
        $item.fadeOut(300, function() {
            $(this).remove();
            markDirty();
        });
    }
    
    /**
     * Carica sostituzioni parziali
     */
    function loadPartialReplacements() {
        // Implementazione caricamento dati esistenti
        // Per ora aggiungiamo un item vuoto
        addPartialItem();
    }
    
    /**
     * Salva sostituzioni parziali
     */
    function savePartialReplacements() {
        const replacements = {};
        
        $('.dpt-partial-item').each(function() {
            const $item = $(this);
            const search = $item.find('.search-text').val().trim();
            const replace = $item.find('.replace-text').val().trim();
            const caseSensitive = $item.find('.case-sensitive').is(':checked');
            
            if (search && replace) {
                replacements[search] = {
                    replace: replace,
                    case_sensitive: caseSensitive
                };
            }
        });
        
        const $button = $('#save-partial-replacements');
        const originalText = $button.text();
        
        $button.prop('disabled', true).text(dptDictionary.strings.saving || 'Salvando...');
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_save_dictionary',
                type: 'partial',
                data: replacements,
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', 'Sostituzioni salvate con successo');
                    markClean();
                } else {
                    showMessage('error', response.data);
                }
            },
            error: function() {
                showMessage('error', 'Errore durante il salvataggio');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }
    
    /**
     * Test sostituzioni parziali
     */
    function testPartialReplacements() {
        const testText = prompt('Inserisci un testo per testare le sostituzioni:', 'Il nostro prodotto XYZ è fantastico');
        if (!testText) return;
        
        const replacements = {};
        $('.dpt-partial-item').each(function() {
            const $item = $(this);
            const search = $item.find('.search-text').val().trim();
            const replace = $item.find('.replace-text').val().trim();
            const caseSensitive = $item.find('.case-sensitive').is(':checked');
            
            if (search && replace) {
                replacements[search] = {
                    replace: replace,
                    case_sensitive: caseSensitive
                };
            }
        });
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_test_dictionary_rule',
                type: 'partial',
                test_text: testText,
                rule_data: replacements,
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    const result = response.data;
                    if (result.modified) {
                        showMessage('success', 'Test riuscito! Risultato: "' + result.result + '"');
                    } else {
                        showMessage('info', 'Nessuna sostituzione applicata');
                    }
                } else {
                    showMessage('error', 'Errore durante il test');
                }
            },
            error: function() {
                showMessage('error', 'Errore di connessione');
            }
        });
    }
    
    /**
     * Setup tab pattern wildcard
     */
    function setupPatternsTab() {
        // Add pattern
        $('#add-wildcard-pattern').on('click', function() {
            addPatternItem();
        });
        
        // Save patterns
        $('#save-patterns').on('click', function() {
            savePatterns();
        });
        
        // Test patterns
        $('#test-patterns').on('click', function() {
            testPatterns();
        });
        
        // Validate patterns
        $('#validate-patterns').on('click', function() {
            validateAllPatterns();
        });
        
        // Event delegation
        $(document).on('click', '.remove-pattern', function() {
            removePatternItem($(this).closest('.dpt-pattern-item'));
        });
        
        $(document).on('click', '.test-pattern', function() {
            testSinglePattern($(this).closest('.dpt-pattern-item'));
        });
        
        $(document).on('input', '.pattern-text', function() {
            updatePatternPreview($(this).closest('.dpt-pattern-item'));
        });
    }
    
    /**
     * Aggiunge item pattern
     */
    function addPatternItem(pattern = '', replacement = '') {
        const template = $('#wildcard-pattern-template').html();
        const index = $('.dpt-pattern-item').length;
        
        const regex = pattern ? convertWildcardToRegex(pattern) : '';
        
        const html = template
            .replace(/{{index}}/g, index)
            .replace(/{{pattern}}/g, pattern)
            .replace(/{{replacement}}/g, replacement)
            .replace(/{{regex}}/g, regex);
        
        $('#wildcard-patterns-list').append(html);
        markDirty();
    }
    
    /**
     * Rimuove item pattern
     */
    function removePatternItem($item) {
        $item.fadeOut(300, function() {
            $(this).remove();
            markDirty();
        });
    }
    
    /**
     * Carica pattern per coppia lingua
     */
    function loadPatterns() {
        if (!currentLangPair) {
            updateCurrentLangPair('patterns');
        }
        
        // Implementazione caricamento pattern
        // Per ora aggiungiamo un item vuoto
        $('#wildcard-patterns-list').empty();
        addPatternItem();
    }
    
    /**
     * Converte wildcard in regex
     */
    function convertWildcardToRegex(pattern) {
        return '^' + pattern.replace(/\*/g, '(.*)') + '$';
    }
    
    /**
     * Aggiorna anteprima pattern
     */
    function updatePatternPreview($item) {
        const pattern = $item.find('.pattern-text').val();
        const regex = pattern ? convertWildcardToRegex(pattern) : '';
        $item.find('.dpt-pattern-preview code').text(regex);
    }
    
    /**
     * Test singolo pattern
     */
    function testSinglePattern($item) {
        const pattern = $item.find('.pattern-text').val().trim();
        const replacement = $item.find('.replacement-text').val().trim();
        
        if (!pattern || !replacement) {
            showMessage('error', 'Inserisci sia il pattern che la sostituzione');
            return;
        }
        
        const testText = prompt('Inserisci un testo per testare il pattern:', 'Version 1.0');
        if (!testText) return;
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_test_dictionary_rule',
                type: 'pattern',
                test_text: testText,
                rule_data: {
                    pattern: pattern,
                    replacement: replacement
                },
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    const result = response.data;
                    if (result.matched) {
                        showMessage('success', `✓ Pattern match! "${testText}" → "${result.result}"`);
                    } else {
                        showMessage('info', '! Pattern non corrisponde al testo di test');
                    }
                } else {
                    showMessage('error', 'Errore durante il test');
                }
            },
            error: function() {
                showMessage('error', 'Errore di connessione');
            }
        });
    }
    
    /**
     * Test pattern con input utente
     */
    function testPatterns() {
        const testText = $('#pattern-test-input').val().trim();
        if (!testText) {
            showMessage('error', 'Inserisci un testo da testare');
            return;
        }
        
        const patterns = {};
        $('.dpt-pattern-item').each(function() {
            const $item = $(this);
            const pattern = $item.find('.pattern-text').val().trim();
            const replacement = $item.find('.replacement-text').val().trim();
            
            if (pattern && replacement) {
                patterns[pattern] = replacement;
            }
        });
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_test_dictionary_rule',
                type: 'patterns',
                test_text: testText,
                rule_data: patterns,
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    const result = response.data;
                    const $resultDiv = $('#pattern-test-result');
                    
                    if (result.matched) {
                        $resultDiv.removeClass('error').addClass('success')
                                 .html(`✓ Match trovato! Risultato: <strong>"${result.result}"</strong>`);
                    } else {
                        $resultDiv.removeClass('success').addClass('error')
                                 .html('! Nessun pattern corrisponde al testo');
                    }
                    $resultDiv.show();
                } else {
                    showMessage('error', 'Errore durante il test');
                }
            },
            error: function() {
                showMessage('error', 'Errore di connessione');
            }
        });
    }
    
    /**
     * Valida tutti i pattern
     */
    function validateAllPatterns() {
        let valid = 0;
        let invalid = 0;
        
        $('.dpt-pattern-item').each(function() {
            const $item = $(this);
            const pattern = $item.find('.pattern-text').val().trim();
            
            if (pattern) {
                try {
                    new RegExp(convertWildcardToRegex(pattern));
                    $item.removeClass('invalid').addClass('valid');
                    valid++;
                } catch (e) {
                    $item.removeClass('valid').addClass('invalid');
                    invalid++;
                }
            }
        });
        
        showMessage('info', `Validazione completata: ${valid} validi, ${invalid} non validi`);
    }
    
    /**
     * Setup tab import/export
     */
    function setupImportExportTab() {
        // Export dictionary
        $('#export-dictionary').on('click', function() {
            exportDictionary();
        });
        
        // Import file upload
        $('#dictionary-upload-area').on('click', function() {
            $('#import-dictionary-file').click();
        });
        
        $('#import-dictionary-file').on('change', function() {
            handleFileUpload(this.files[0]);
        });
        
        // Import dictionary
        $('#import-dictionary').on('click', function() {
            importDictionary();
        });
        
        // Template loaders
        $('.load-template').on('click', function() {
            const template = $(this).closest('.dpt-template-card').data('template');
            loadTemplate(template);
        });
        
        // Drag & drop
        setupDragDrop();
        
        // Update dictionary size
        updateDictionarySize();
    }
    
    /**
     * Setup drag & drop
     */
    function setupDragDrop() {
        const $dropArea = $('#dictionary-upload-area');
        
        $dropArea.on('dragover dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        });
        
        $dropArea.on('dragleave dragend drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        });
        
        $dropArea.on('drop', function(e) {
            e.preventDefault();
            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handleFileUpload(files[0]);
            }
        });
    }
    
    /**
     * Gestisce upload file
     */
    function handleFileUpload(file) {
        if (!file) return;
        
        const allowedTypes = ['application/json', 'text/csv', 'application/xml'];
        const allowedExtensions = ['.json', '.csv', '.xml'];
        
        const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
        
        if (!allowedTypes.includes(file.type) && !allowedExtensions.includes(fileExtension)) {
            showMessage('error', 'Formato file non supportato. Usa JSON, CSV o XML.');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const content = e.target.result;
                previewImport(content, fileExtension);
            } catch (error) {
                showMessage('error', 'Errore nella lettura del file');
            }
        };
        reader.readAsText(file);
    }
    
    /**
     * Anteprima import
     */
    function previewImport(content, extension) {
        let data;
        
        try {
            if (extension === '.json') {
                data = JSON.parse(content);
            } else if (extension === '.csv') {
                data = parseCSV(content);
            } else if (extension === '.xml') {
                data = parseXML(content);
            }
            
            // Mostra anteprima
            $('#import-preview-content').html(JSON.stringify(data, null, 2));
            $('#import-options, #import-preview, #import-dictionary').show();
            
        } catch (error) {
            showMessage('error', 'Formato file non valido: ' + error.message);
        }
    }
    
    /**
     * Esporta dizionario
     */
    function exportDictionary() {
        const options = {
            translations: $('#export-translations').is(':checked'),
            exclusions: $('#export-exclusions').is(':checked'),
            partial: $('#export-partial').is(':checked'),
            patterns: $('#export-patterns').is(':checked')
        };
        
        const format = $('input[name="export-format"]:checked').val();
        
        const $button = $('#export-dictionary');
        const originalText = $button.text();
        
        $button.prop('disabled', true).text('Esportando...');
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_export_dictionary',
                options: options,
                format: format,
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    downloadFile(response.data.content, response.data.filename, response.data.format);
                    showMessage('success', dptDictionary.strings.exportSuccess || 'Dizionario esportato con successo');
                    $('#last-export-date').text(new Date().toLocaleString());
                } else {
                    showMessage('error', response.data);
                }
            },
            error: function() {
                showMessage('error', 'Errore durante l\'esportazione');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }
    
    /**
     * Importa dizionario
     */
    function importDictionary() {
        const file = $('#import-dictionary-file')[0].files[0];
        if (!file) {
            showMessage('error', 'Seleziona un file da importare');
            return;
        }
        
        const formData = new FormData();
        formData.append('dictionary_file', file);
        formData.append('action', 'dpt_import_dictionary');
        formData.append('merge', $('#merge-import').is(':checked'));
        formData.append('overwrite', $('#overwrite-duplicates').is(':checked'));
        formData.append('backup', $('#backup-before-import').is(':checked'));
        formData.append('nonce', dptDictionary.nonce);
        
        const $button = $('#import-dictionary');
        const originalText = $button.text();
        
        $button.prop('disabled', true).text('Importando...');
        
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    showMessage('success', 'Dizionario importato con successo');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage('error', response.data);
                }
            },
            error: function() {
                showMessage('error', 'Errore durante l\'importazione');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }
    
    /**
     * Carica template
     */
    function loadTemplate(templateType) {
        if (confirm('Questo sostituirà il dizionario corrente. Continuare?')) {
            $.ajax({
                url: dptDictionary.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dpt_load_template',
                    template: templateType,
                    nonce: dptDictionary.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showMessage('success', 'Template caricato con successo');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showMessage('error', response.data);
                    }
                },
                error: function() {
                    showMessage('error', 'Errore durante il caricamento del template');
                }
            });
        }
    }
    
    /**
     * Setup tab statistiche
     */
    function setupStatisticsTab() {
        // Refresh stats
        $('#refresh-stats').on('click', function() {
            refreshStatistics();
        });
        
        // Export stats
        $('#export-stats').on('click', function() {
            exportStatistics();
        });
        
        // Reset stats
        $('#reset-stats').on('click', function() {
            if (confirm('Sei sicuro di voler resettare tutte le statistiche?')) {
                resetStatistics();
            }
        });
        
        // Load charts if Chart.js is available
        if (typeof Chart !== 'undefined') {
            loadStatisticsCharts();
        }
    }
    
    /**
     * Aggiorna statistiche
     */
    function refreshStatistics() {
        $.ajax({
            url: dptDictionary.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dpt_get_dictionary_stats',
                nonce: dptDictionary.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateStatisticsDisplay(response.data);
                    showMessage('success', 'Statistiche aggiornate');
                } else {
                    showMessage('error', response.data);
                }
            },
            error: function() {
                showMessage('error', 'Errore durante l\'aggiornamento');
            }
        });
    }
    
    /**
     * Aggiorna display statistiche
     */
    function updateStatisticsDisplay(stats) {
        $('.dpt-stat-number').each(function() {
            const stat = $(this).data('stat');
            if (stats[stat] !== undefined) {
                animateNumber($(this), stats[stat]);
            }
        });
    }
    
    /**
     * Anima numero
     */
    function animateNumber($element, newValue) {
        const currentValue = parseInt($element.text().replace(/,/g, '')) || 0;
        const diff = newValue - currentValue;
        const steps = 30;
        const increment = diff / steps;
        let step = 0;
        
        const timer = setInterval(() => {
            step++;
            const value = Math.round(currentValue + (increment * step));
            $element.text(value.toLocaleString());
            
            if (step >= steps) {
                clearInterval(timer);
                $element.text(newValue.toLocaleString());
            }
        }, 50);
    }
    
    /**
     * Setup auto-save
     */
    function setupAutoSave() {
        let saveTimer;
        
        $(document).on('input change', 'input, textarea, select', function() {
            if ($(this).hasClass('no-autosave')) return;
            
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => {
                if (isDirty) {
                    // Implementa auto-save specifico per sezione
                    console.log('Auto-save triggered');
                }
            }, 3000);
        });
    }
    
    /**
     * Setup scorciatoie tastiera
     */
    function setupKeyboardShortcuts() {
        $(document).on('keydown', function(e) {
            // Ctrl+S per salvare
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                const activeTab = $('.nav-tab-active').attr('href').split('tab=')[1];
                
                switch (activeTab) {
                    case 'translations':
                        $('#save-translations').click();
                        break;
                    case 'exclusions':
                        $('#save-exclusions').click();
                        break;
                    case 'partial':
                        $('#save-partial-replacements').click();
                        break;
                    case 'patterns':
                        $('#save-patterns').click();
                        break;
                }
            }
            
            // Ctrl+T per test
            if (e.ctrlKey && e.key === 't') {
                e.preventDefault();
                const activeTab = $('.nav-tab-active').attr('href').split('tab=')[1];
                
                switch (activeTab) {
                    case 'translations':
                        $('#test-all-translations').click();
                        break;
                    case 'exclusions':
                        $('#test-exclusions').click();
                        break;
                    case 'partial':
                        $('#test-partial-replacements').click();
                        break;
                    case 'patterns':
                        $('#test-patterns').click();
                        break;
                }
            }
        });
    }
    
    /**
     * Utility functions
     */
    function markDirty() {
        isDirty = true;
        $(document.body).addClass('dpt-unsaved-changes');
    }
    
    function markClean() {
        isDirty = false;
        $(document.body).removeClass('dpt-unsaved-changes');
    }
    
    function checkUnsavedChanges() {
        if (isDirty) {
            return 'Hai modifiche non salvate. Vuoi davvero uscire?';
        }
    }
    
    function showMessage(type, message) {
        const $message = $(`<div class="dpt-${type}-message">${message}</div>`);
        $('.dpt-dictionary-content').prepend($message);
        
        setTimeout(() => {
            $message.fadeOut(() => $message.remove());
        }, type === 'error' ? 5000 : 3000);
    }
    
    function showTestResult($item, type, message) {
        const $result = $item.find('.dpt-test-result');
        $result.removeClass('success error loading')
               .addClass(type)
               .html(message)
               .show();
        
        setTimeout(() => {
            $result.fadeOut();
        }, 5000);
    }
    
    function downloadFile(content, filename, format) {
        const blob = new Blob([content], { 
            type: format === 'json' ? 'application/json' : 
                  format === 'csv' ? 'text/csv' : 'application/xml'
        });
        
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
    
    function updateDictionarySize() {
        // Calcola dimensione approssimativa
        let size = 0;
        // Implementa calcolo dimensione
        $('#dictionary-size').text(formatBytes(size));
    }
    
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function parseCSV(content) {
        // Implementazione parsing CSV semplificata
        const lines = content.split('\n');
        const result = {};
        
        lines.forEach(line => {
            const [original, translation] = line.split(',');
            if (original && translation) {
                result[original.trim()] = translation.trim();
            }
        });
        
        return { exact_translations: { 'en_it': result } };
    }
    
    function parseXML(content) {
        // Implementazione parsing XML semplificata
        return {};
    }
    
    // Inizializza quando il documento è pronto
    $(document).ready(init);
    
    // Espone API globale
    window.DPTDictionary = {
        markDirty: markDirty,
        markClean: markClean,
        showMessage: showMessage,
        addTranslationItem: addTranslationItem,
        addPartialItem: addPartialItem,
        addPatternItem: addPatternItem,
        refreshStatistics: refreshStatistics
    };
    
})(jQuery);