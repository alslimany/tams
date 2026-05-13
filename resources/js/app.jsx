import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { route } from 'ziggy-js';
import { DirectionProvider } from '@/Components/ui/direction';
import { TooltipProvider } from '@/Components/ui/tooltip';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);
        
        // 1. Read locale from the reactive props object rather than initialPage
        const locale = props.props?.locale || props.initialPage?.props?.locale || 'en';
        const direction = locale === 'ar' ? 'rtl' : 'ltr';

        // 2. Make Ziggy dynamically read from the active route props on every render
        window.route = (name, params, absolute, config = props.props?.ziggy || props.initialPage?.props?.ziggy) => {
            return route(name, params, absolute, config);
        };

        root.render(
            <DirectionProvider dir={direction}>
                <div dir={direction} className={direction === 'rtl' ? 'rtl' : undefined}>
                    <TooltipProvider>
                        <App {...props} />
                    </TooltipProvider>
                </div>
            </DirectionProvider>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});
