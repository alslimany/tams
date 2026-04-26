import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { route } from 'ziggy-js';
import { DirectionProvider } from "@/components/ui/direction"

import { TooltipProvider } from '@/Components/ui/tooltip';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        window.route = route;
        root.render(
             <DirectionProvider dir="rtl"> 
                <TooltipProvider>
                    <App {...props} />
                </TooltipProvider>
            </DirectionProvider>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});
