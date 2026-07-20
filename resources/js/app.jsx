import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { route } from 'ziggy-js';
import LocaleDirectionProvider from '@/Components/LocaleDirectionProvider';
import { TooltipProvider } from '@/Components/ui/tooltip';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');

        return resolvePageComponent(`./Pages/${name}.jsx`, pages).then((module) => {
            const Page = module.default;

            return function ResolvedPage(props) {
                return (
                    <LocaleDirectionProvider>
                        <TooltipProvider>
                            <Page {...props} />
                        </TooltipProvider>
                    </LocaleDirectionProvider>
                );
            };
        });
    },
    setup({ el, App, props }) {
        const root = createRoot(el);

        window.route = (name, params, absolute, config = props.props?.ziggy || props.initialPage?.props?.ziggy) => {
            return route(name, params, absolute, config);
        };

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
