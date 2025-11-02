( function ( wp, appData ) {
    if ( ! wp || ! appData ) {
        return;
    }

    const { createElement: el, Fragment, render, useState, useEffect, useMemo } = wp.element;
    const { __ } = wp.i18n;
    const {
        Button,
        Card,
        CardBody,
        CardHeader,
        TextControl,
        TextareaControl,
        SelectControl,
        ToggleControl,
        Notice,
        Spinner,
        Panel,
        PanelBody,
    } = wp.components;

    const modelsData = Array.isArray( appData.connections ) ? appData.connections : [];
    const settingsData = appData.settings || {};
    const i18n = appData.i18n || {};

    const sendRequest = ( action, payload = {} ) => {
        const body = new window.URLSearchParams( { action, nonce: appData.nonce } );
        Object.keys( payload ).forEach( ( key ) => {
            const value = payload[ key ];
            if ( Array.isArray( value ) ) {
                value.forEach( ( item ) => body.append( `${ key }[]`, item ) );
            } else if ( value !== undefined && value !== null ) {
                body.append( key, value );
            }
        } );

        return window.fetch( appData.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body,
        } ).then( async ( response ) => {
            const result = await response.json();
            if ( ! response.ok || result.success === false ) {
                const message = result?.data?.message || i18n.saveError || __( 'Something went wrong.', 'ai-plugin-builder-studio' );
                throw new Error( message );
            }
            return result.data || {};
        } );
    };

    const createSlug = ( value ) => ( value || '' )
        .toString()
        .trim()
        .toLowerCase()
        .replace( /[^a-z0-9\-]+/g, '-' )
        .replace( /-{2,}/g, '-' )
        .replace( /^-|-$|/g, '' );

    const ConnectModelApp = () => {
        const [ selectedModel, setSelectedModel ] = useState( modelsData[ 0 ]?.id || 'cursor' );
        const [ apiKey, setApiKey ] = useState( '' );
        const [ endpoint, setEndpoint ] = useState( '' );
        const [ notice, setNotice ] = useState( null );
        const [ busy, setBusy ] = useState( false );
        const [ connections, setConnections ] = useState( modelsData );

        const active = useMemo( () => connections.find( ( item ) => item.id === selectedModel ), [ connections, selectedModel ] );

        useEffect( () => {
            if ( active && active.connection.connected ) {
                setEndpoint( active.connection.endpoint || '' );
            } else {
                setEndpoint( '' );
            }
            setApiKey( '' );
            setNotice( null );
        }, [ selectedModel ] );

        const handleSubmit = async ( event ) => {
            event.preventDefault();

            if ( ! apiKey ) {
                setNotice( { status: 'error', message: __( 'Please provide an API key.', 'ai-plugin-builder-studio' ) } );
                return;
            }

            setBusy( true );
            setNotice( null );

            try {
                const data = await sendRequest( 'ai_pbs_save_connection', {
                    model: selectedModel,
                    apiKey,
                    endpoint,
                } );

                setConnections( ( current ) => current.map( ( item ) => ( item.id === selectedModel ? { ...item, connection: data.connection } : item ) ) );
                setApiKey( '' );
                setNotice( { status: 'success', message: data.message || i18n.saveSuccess } );
            } catch ( error ) {
                setNotice( { status: 'error', message: error.message } );
            } finally {
                setBusy( false );
            }
        };

        const handleDisconnect = async () => {
            if ( ! window.confirm( __( 'Disconnect this AI provider?', 'ai-plugin-builder-studio' ) ) ) {
                return;
            }

            setBusy( true );
            setNotice( null );

            try {
                await sendRequest( 'ai_pbs_disconnect_model', { model: selectedModel } );
                setConnections( ( current ) => current.map( ( item ) => ( item.id === selectedModel ? { ...item, connection: { connected: false } } : item ) ) );
                setNotice( { status: 'success', message: __( 'Connection removed.', 'ai-plugin-builder-studio' ) } );
            } catch ( error ) {
                setNotice( { status: 'error', message: error.message } );
            } finally {
                setBusy( false );
            }
        };

        return el( Card, { className: 'ai-pbs-model-card-grid' },
            el( CardBody, null,
                el( 'div', { className: 'ai-pbs-model-select' },
                    el( SelectControl, {
                        label: __( 'AI Provider', 'ai-plugin-builder-studio' ),
                        value: selectedModel,
                        options: connections.map( ( model ) => ( { label: model.label, value: model.id } ) ),
                        onChange: ( value ) => setSelectedModel( value ),
                    } ),
                    active && el( 'p', { className: 'description' }, active.description )
                ),
                active && active.connection.connected && el( Notice, { status: 'success', isDismissible: false },
                    el( 'strong', null, __( 'Connected', 'ai-plugin-builder-studio' ) ),
                    el( 'br' ),
                    el( 'span', null, __( 'API key ending with:', 'ai-plugin-builder-studio' ), ' ', active.connection.maskedKey || __( 'hidden', 'ai-plugin-builder-studio' ) )
                ),
                notice && el( Notice, { status: notice.status, isDismissible: true, onRemove: () => setNotice( null ) }, notice.message ),
                el( 'form', { onSubmit: handleSubmit, className: 'ai-pbs-model-form' },
                    el( TextControl, {
                        label: __( 'API Key', 'ai-plugin-builder-studio' ),
                        value: apiKey,
                        type: 'password',
                        onChange: setApiKey,
                        help: __( 'Keys are encrypted before storage and never exposed after saving.', 'ai-plugin-builder-studio' ),
                    } ),
                    active?.requiresEndpoint && el( TextControl, {
                        label: __( 'Endpoint URL', 'ai-plugin-builder-studio' ),
                        value: endpoint,
                        onChange: setEndpoint,
                        placeholder: 'https://',
                    } ),
                    el( 'div', { className: 'ai-pbs-actions' },
                        el( Button, {
                            variant: 'primary',
                            type: 'submit',
                            isBusy: busy,
                            disabled: busy || ! apiKey,
                        }, busy ? __( 'Saving?', 'ai-plugin-builder-studio' ) : __( 'Save Connection', 'ai-plugin-builder-studio' ) ),
                        active?.connection?.connected && el( Button, {
                            variant: 'secondary',
                            disabled: busy,
                            onClick: handleDisconnect,
                        }, __( 'Disconnect', 'ai-plugin-builder-studio' ) )
                    )
                )
            )
        );
    };

    const CreatePluginApp = () => {
        const modelOptions = useMemo( () => connectionsToModelOptions( modelsData ), [] );
        const [ step, setStep ] = useState( 1 );
        const [ notice, setNotice ] = useState( null );
        const [ isAnalyzing, setIsAnalyzing ] = useState( false );
        const [ isGenerating, setIsGenerating ] = useState( false );
        const [ analysisFetched, setAnalysisFetched ] = useState( false );
        const [ recommendations, setRecommendations ] = useState( [] );
        const [ generationResult, setGenerationResult ] = useState( null );
        const [ form, setForm ] = useState( {
            pluginName: '',
            description: '',
            version: '1.0.0',
            slug: '',
            prompt: '',
            features: {},
            notes: '',
            model: modelOptions[0]?.value || 'cursor',
        } );

        const featureSelections = useMemo( () => Object.keys( form.features ).filter( ( key ) => form.features[ key ] ), [ form.features ] );

        const updateForm = ( key, value ) => {
            setForm( ( current ) => ( { ...current, [ key ]: value } ) );
        };

        const handleBasicNext = () => {
            if ( ! form.pluginName || ! form.prompt ) {
                setNotice( { status: 'error', message: __( 'Please provide a plugin name and prompt before continuing.', 'ai-plugin-builder-studio' ) } );
                return;
            }

            const slug = form.slug ? createSlug( form.slug ) : createSlug( form.pluginName );
            updateForm( 'slug', slug );
            setNotice( null );
            setStep( 2 );
        };

        const handleAnalyzePrompt = async () => {
            if ( ! form.prompt ) {
                setNotice( { status: 'error', message: __( 'Add a prompt so the assistant can make suggestions.', 'ai-plugin-builder-studio' ) } );
                return;
            }

            setIsAnalyzing( true );
            setNotice( null );

            try {
                const data = await sendRequest( 'ai_pbs_analyze_prompt', { prompt: form.prompt } );
                setRecommendations( data.recommendations || [] );
                const initialFeatures = {};
                ( data.recommendations || [] ).forEach( ( item ) => {
                    initialFeatures[ item.id ] = !! item.selected;
                } );
                updateForm( 'features', { ...form.features, ...initialFeatures } );
                setAnalysisFetched( true );
            } catch ( error ) {
                setNotice( { status: 'error', message: error.message } );
            } finally {
                setIsAnalyzing( false );
            }
        };

        const handleGenerate = async () => {
            setIsGenerating( true );
            setNotice( null );
            setGenerationResult( null );

            try {
                const payload = {
                    pluginName: form.pluginName,
                    description: form.description,
                    version: form.version,
                    slug: form.slug,
                    prompt: form.prompt,
                    features: featureSelections,
                    notes: form.notes,
                    model: form.model,
                };

                const data = await sendRequest( 'ai_pbs_generate_plugin', payload );
                setGenerationResult( data );
                setStep( 4 );
            } catch ( error ) {
                setNotice( { status: 'error', message: error.message } );
            } finally {
                setIsGenerating( false );
            }
        };

        const resetWizard = () => {
            setForm( {
                pluginName: '',
                description: '',
                version: '1.0.0',
                slug: '',
                prompt: '',
                features: {},
                notes: '',
                model: modelOptions[0]?.value || 'cursor',
            } );
            setRecommendations( [] );
            setGenerationResult( null );
            setNotice( null );
            setIsAnalyzing( false );
            setIsGenerating( false );
            setAnalysisFetched( false );
            setStep( 1 );
        };

        return el( Fragment, null,
            notice && el( Notice, { status: notice.status, isDismissible: true, onRemove: () => setNotice( null ) }, notice.message ),
            step === 1 && el( Panel, { className: 'ai-pbs-step' },
                el( PanelBody, { title: __( 'Plugin basics', 'ai-plugin-builder-studio' ), initialOpen: true },
                    el( TextControl, {
                        label: __( 'Plugin name', 'ai-plugin-builder-studio' ),
                        value: form.pluginName,
                        onChange: ( value ) => updateForm( 'pluginName', value ),
                    } ),
                    el( TextControl, {
                        label: __( 'Description', 'ai-plugin-builder-studio' ),
                        value: form.description,
                        onChange: ( value ) => updateForm( 'description', value ),
                    } ),
                    el( TextControl, {
                        label: __( 'Version', 'ai-plugin-builder-studio' ),
                        value: form.version,
                        onChange: ( value ) => updateForm( 'version', value ),
                    } ),
                    el( TextControl, {
                        label: __( 'Slug (optional)', 'ai-plugin-builder-studio' ),
                        help: __( 'Leave blank to generate automatically.', 'ai-plugin-builder-studio' ),
                        value: form.slug,
                        onChange: ( value ) => updateForm( 'slug', value ),
                    } ),
                    el( TextareaControl, {
                        label: __( 'Describe what the plugin should do', 'ai-plugin-builder-studio' ),
                        value: form.prompt,
                        rows: 6,
                        onChange: ( value ) => updateForm( 'prompt', value ),
                    } ),
                    el( 'div', { className: 'ai-pbs-actions' },
                        el( Button, { variant: 'primary', onClick: handleBasicNext }, __( 'Next', 'ai-plugin-builder-studio' ) )
                    )
                )
            ),
            step === 2 && el( Panel, { className: 'ai-pbs-step' },
                el( PanelBody, { title: __( 'Clarify plugin capabilities', 'ai-plugin-builder-studio' ), initialOpen: true },
                    el( 'p', null, __( 'The assistant can suggest features to accelerate development. Analyse your prompt or toggle items manually.', 'ai-plugin-builder-studio' ) ),
                    el( 'div', { className: 'ai-pbs-actions' },
                        el( Button, { variant: 'secondary', onClick: handleAnalyzePrompt, isBusy: isAnalyzing }, isAnalyzing ? __( 'Analyzing?', 'ai-plugin-builder-studio' ) : __( 'Analyze Prompt', 'ai-plugin-builder-studio' ) ),
                        el( Button, { variant: 'link', onClick: () => setStep( 3 ), disabled: isAnalyzing }, __( 'Skip to summary', 'ai-plugin-builder-studio' ) )
                    ),
                    ( recommendations.length > 0 || analysisFetched ) && el( 'div', { className: 'ai-pbs-tag-list' },
                        recommendations.map( ( item ) => el( ToggleControl, {
                            label: item.label,
                            checked: !! form.features[ item.id ],
                            onChange: ( checked ) => updateForm( 'features', { ...form.features, [ item.id ]: checked } ),
                            key: item.id,
                        } ) )
                    ),
                    el( TextareaControl, {
                        label: __( 'Additional instructions', 'ai-plugin-builder-studio' ),
                        value: form.notes,
                        onChange: ( value ) => updateForm( 'notes', value ),
                        rows: 4,
                    } ),
                    el( SelectControl, {
                        label: __( 'Generation model', 'ai-plugin-builder-studio' ),
                        value: form.model,
                        options: modelOptions,
                        onChange: ( value ) => updateForm( 'model', value ),
                    } ),
                    el( 'div', { className: 'ai-pbs-actions' },
                        el( Button, { variant: 'secondary', onClick: () => setStep( 1 ) }, __( 'Back', 'ai-plugin-builder-studio' ) ),
                        el( Button, { variant: 'primary', onClick: () => setStep( 3 ), disabled: isAnalyzing }, __( 'Review summary', 'ai-plugin-builder-studio' ) )
                    )
                )
            ),
            step === 3 && el( Panel, { className: 'ai-pbs-step' },
                el( PanelBody, { title: __( 'Review & generate', 'ai-plugin-builder-studio' ), initialOpen: true },
                    el( 'dl', { className: 'ai-pbs-summary' },
                        el( 'dt', null, __( 'Plugin name', 'ai-plugin-builder-studio' ) ),
                        el( 'dd', null, form.pluginName ),
                        el( 'dt', null, __( 'Slug', 'ai-plugin-builder-studio' ) ),
                        el( 'dd', null, form.slug || createSlug( form.pluginName ) ),
                        el( 'dt', null, __( 'Version', 'ai-plugin-builder-studio' ) ),
                        el( 'dd', null, form.version ),
                        el( 'dt', null, __( 'Model', 'ai-plugin-builder-studio' ) ),
                        el( 'dd', null, ( modelOptions.find( ( item ) => item.value === form.model ) || {} ).label || form.model ),
                        featureSelections.length > 0 && el( Fragment, null,
                            el( 'dt', null, __( 'Features', 'ai-plugin-builder-studio' ) ),
                            el( 'dd', null, featureSelections.join( ', ' ) )
                        )
                    ),
                    el( 'div', { className: 'ai-pbs-actions' },
                        el( Button, { variant: 'secondary', onClick: () => setStep( 2 ) }, __( 'Back', 'ai-plugin-builder-studio' ) ),
                        el( Button, { variant: 'primary', onClick: handleGenerate, isBusy: isGenerating }, isGenerating ? ( i18n.generating || __( 'Generating?', 'ai-plugin-builder-studio' ) ) : __( 'Generate Plugin', 'ai-plugin-builder-studio' ) )
                    ),
                    isGenerating && el( 'div', { className: 'ai-pbs-progress' }, el( 'div', { className: 'ai-pbs-progress__bar' } ) )
                )
            ),
            step === 4 && el( Panel, { className: 'ai-pbs-step' },
                el( PanelBody, { title: __( 'Generation complete', 'ai-plugin-builder-studio' ), initialOpen: true },
                    generationResult && el( Notice, { status: generationResult.activated ? 'success' : 'warning', isDismissible: false }, generationResult.message ),
                    generationResult && el( 'div', { className: 'ai-pbs-summary' },
                        el( 'p', null, __( 'Plugin slug:', 'ai-plugin-builder-studio' ), ' ', el( 'strong', null, generationResult.pluginSlug ) ),
                        generationResult.pluginFile && el( 'p', null, __( 'Main file:', 'ai-plugin-builder-studio' ), ' ', el( 'code', null, generationResult.pluginFile ) ),
                        generationResult.files && generationResult.files.length > 0 && el( 'ul', null,
                            generationResult.files.map( ( file ) => el( 'li', { key: file }, file ) )
                        )
                    ),
                    el( 'div', { className: 'ai-pbs-actions' },
                        el( Button, { variant: 'primary', onClick: resetWizard }, __( 'Generate another plugin', 'ai-plugin-builder-studio' ) )
                    )
                )
            )
        );
    };

    const connectionsToModelOptions = ( data ) => data.map( ( item ) => ( { label: item.label, value: item.id } ) );

    const ManagePluginsApp = () => {
        const [ plugins, setPlugins ] = useState( [] );
        const [ loading, setLoading ] = useState( true );
        const [ notice, setNotice ] = useState( null );
        const [ modal, setModal ] = useState( null );
        const [ processingSlug, setProcessingSlug ] = useState( '' );

        const loadPlugins = () => {
            setLoading( true );
            sendRequest( 'ai_pbs_fetch_plugins' )
                .then( ( data ) => {
                    setPlugins( data.plugins || [] );
                } )
                .catch( ( error ) => setNotice( { status: 'error', message: error.message } ) )
                .finally( () => setLoading( false ) );
        };

        useEffect( () => {
            loadPlugins();
        }, [] );

        const handleAction = ( slug, actionType ) => {
            if ( 'delete' === actionType && ! window.confirm( i18n.confirmDelete || __( 'Are you sure?', 'ai-plugin-builder-studio' ) ) ) {
                return;
            }

            setProcessingSlug( slug + actionType );
            setNotice( null );

            sendRequest( 'ai_pbs_plugin_action', { pluginSlug: slug, actionType } )
                .then( ( data ) => {
                    if ( 'view_code' === actionType ) {
                        setModal( { slug, files: data.codePreview || [] } );
                    } else {
                        loadPlugins();
                        setNotice( { status: 'success', message: i18n.saveSuccess || __( 'Operation completed.', 'ai-plugin-builder-studio' ) } );
                    }
                } )
                .catch( ( error ) => setNotice( { status: 'error', message: error.message } ) )
                .finally( () => setProcessingSlug( '' ) );
        };

        return el( Fragment, null,
            notice && el( Notice, { status: notice.status, isDismissible: true, onRemove: () => setNotice( null ) }, notice.message ),
            loading ? el( Spinner, null ) : el( 'table', { className: 'ai-pbs-table' },
                el( 'thead', null,
                    el( 'tr', null,
                        el( 'th', null, __( 'Plugin', 'ai-plugin-builder-studio' ) ),
                        el( 'th', null, __( 'AI Model', 'ai-plugin-builder-studio' ) ),
                        el( 'th', null, __( 'Status', 'ai-plugin-builder-studio' ) ),
                        el( 'th', null, __( 'Actions', 'ai-plugin-builder-studio' ) )
                    )
                ),
                el( 'tbody', null,
                    plugins.map( ( plugin ) => {
                        const isActive = 'active' === plugin.status;
                        return el( 'tr', { key: plugin.slug },
                            el( 'td', null,
                                el( 'strong', null, plugin.name ),
                                el( 'div', { className: 'description' }, plugin.description )
                            ),
                            el( 'td', null, plugin.aiModel || '?' ),
                            el( 'td', null,
                                el( 'span', { className: 'ai-pbs-status-badge' + ( isActive ? '' : ' is-inactive' ) }, isActive ? __( 'Active', 'ai-plugin-builder-studio' ) : __( 'Inactive', 'ai-plugin-builder-studio' ) )
                            ),
                            el( 'td', null,
                                el( 'div', { className: 'ai-pbs-actions' },
                                    el( Button, {
                                        variant: 'secondary',
                                        disabled: isActive || processingSlug === plugin.slug + 'activate',
                                        isBusy: processingSlug === plugin.slug + 'activate',
                                        onClick: () => handleAction( plugin.slug, 'activate' ),
                                    }, __( 'Activate', 'ai-plugin-builder-studio' ) ),
                                    el( Button, {
                                        variant: 'secondary',
                                        disabled: ! isActive || processingSlug === plugin.slug + 'deactivate',
                                        isBusy: processingSlug === plugin.slug + 'deactivate',
                                        onClick: () => handleAction( plugin.slug, 'deactivate' ),
                                    }, __( 'Deactivate', 'ai-plugin-builder-studio' ) ),
                                    el( Button, {
                                        variant: 'secondary',
                                        disabled: processingSlug === plugin.slug + 'view_code',
                                        isBusy: processingSlug === plugin.slug + 'view_code',
                                        onClick: () => handleAction( plugin.slug, 'view_code' ),
                                    }, __( 'View Code', 'ai-plugin-builder-studio' ) ),
                                    el( Button, {
                                        variant: 'secondary',
                                        className: 'ai-pbs-button-danger',
                                        disabled: processingSlug === plugin.slug + 'delete',
                                        isBusy: processingSlug === plugin.slug + 'delete',
                                        onClick: () => handleAction( plugin.slug, 'delete' ),
                                    }, __( 'Delete', 'ai-plugin-builder-studio' ) )
                                )
                            )
                        );
                    } )
                )
            ),
            modal && el( 'div', { className: 'ai-pbs-modal-overlay', role: 'dialog', 'aria-modal': 'true' },
                el( 'div', { className: 'ai-pbs-modal' },
                    el( 'div', { className: 'ai-pbs-actions', style: { justifyContent: 'flex-end' } },
                        el( Button, { onClick: () => setModal( null ) }, __( 'Close', 'ai-plugin-builder-studio' ) )
                    ),
                    el( 'h3', null, __( 'Code preview:', 'ai-plugin-builder-studio' ), ' ', modal.slug ),
                    modal.files.length === 0 ? el( 'p', null, __( 'No files available for preview.', 'ai-plugin-builder-studio' ) ) : modal.files.map( ( file ) => el( 'div', { key: file.path },
                        el( 'h4', null, file.path ),
                        el( 'pre', null, file.content )
                    ) )
                )
            )
        );
    };

    const SettingsApp = () => {
        const [ saving, setSaving ] = useState( false );
        const [ notice, setNotice ] = useState( null );
        const [ defaultModel, setDefaultModel ] = useState( settingsData.defaultModel || 'cursor' );
        const [ debugLogsEnabled, setDebugLogsEnabled ] = useState( !! settingsData.debugLogsEnabled );
        const [ allowLiveDocs, setAllowLiveDocs ] = useState( !! settingsData.allowLiveDocs );

        const modelOptions = connectionsToModelOptions( modelsData );

        const handleSave = () => {
            setSaving( true );
            setNotice( null );

            sendRequest( 'ai_pbs_save_settings', {
                defaultModel,
                debugLogsEnabled: debugLogsEnabled ? 1 : 0,
                allowLiveDocs: allowLiveDocs ? 1 : 0,
            } ).then( ( data ) => {
                setNotice( { status: 'success', message: data.message || i18n.saveSuccess || __( 'Settings saved successfully.', 'ai-plugin-builder-studio' ) } );
            } ).catch( ( error ) => {
                setNotice( { status: 'error', message: error.message } );
            } ).finally( () => setSaving( false ) );
        };

        return el( Fragment, null,
            notice && el( Notice, { status: notice.status, isDismissible: true, onRemove: () => setNotice( null ) }, notice.message ),
            el( Panel, { className: 'ai-pbs-settings-panel' },
                el( PanelBody, { title: __( 'Defaults', 'ai-plugin-builder-studio' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Default AI model', 'ai-plugin-builder-studio' ),
                        value: defaultModel,
                        options: modelOptions,
                        onChange: setDefaultModel,
                    } ),
                    el( ToggleControl, {
                        label: __( 'Enable debug logs', 'ai-plugin-builder-studio' ),
                        checked: debugLogsEnabled,
                        onChange: setDebugLogsEnabled,
                        help: __( 'Stores generation requests under wp-content/uploads/ai-builder-logs/.', 'ai-plugin-builder-studio' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Allow live documentation fetching', 'ai-plugin-builder-studio' ),
                        checked: allowLiveDocs,
                        onChange: setAllowLiveDocs,
                        help: __( 'Permits AI to retrieve real-time docs when crafting plugins.', 'ai-plugin-builder-studio' ),
                    } ),
                    el( Button, { variant: 'primary', isBusy: saving, onClick: handleSave }, saving ? __( 'Saving?', 'ai-plugin-builder-studio' ) : __( 'Save Settings', 'ai-plugin-builder-studio' ) )
                )
            )
        );
    };

    const mountComponent = ( selector, Component ) => {
        const node = document.getElementById( selector );
        if ( node ) {
            render( el( Component ), node );
        }
    };

    document.addEventListener( 'DOMContentLoaded', () => {
        mountComponent( 'ai-pbs-connect-root', ConnectModelApp );
        mountComponent( 'ai-pbs-create-root', CreatePluginApp );
        mountComponent( 'ai-pbs-manage-root', ManagePluginsApp );
        mountComponent( 'ai-pbs-settings-root', SettingsApp );
    } );
} )( window.wp, window.AI_PBS_APP );
