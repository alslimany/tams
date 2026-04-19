UI Development Instructions: shadcn/ui + Laravel Inertia 
1. Core Framework Stack
Backend: Laravel (Inertia.js)
Frontend: React 19 (TypeScript)
Styling: Tailwind CSS (v3 or v4)
UI Library: shadcn/ui (Radix UI primitives)
Icons: Lucide React 
2. Strict UI Enforcement Rules
No Generic HTML: Never use raw <button>, <input>, or <table> tags. If a shadcn component exists, you must use it (e.g., <Button>, <Input>, <DataTable>).
Import Pathing: All shadcn components are located in @/components/ui/.
Inertia Compatibility: Always use the Link component from @inertiajs/react for internal navigation. Do not use <a> or standard React Router links.
Form Handling: Use the useForm hook from @inertiajs/react for state management and validation. Wrap shadcn components with these hooks. 
3. Theming & Identity (Light/Dark Mode)
Light Mode: Palette must be Blue and White. Use bg-white, text-slate-950, and bg-blue-600 for primary actions.
Dark Mode: Palette must be Black and Yellow.
Target the .dark class or dark: utility.
Backgrounds should be bg-black or bg-zinc-950.
Primary highlights and accents must be Yellow (e.g., text-yellow-400, bg-yellow-400, border-yellow-400).
Tokens: Reference CSS variables (e.g., var(--primary)) rather than hardcoding hex values where possible to allow for global theme changes. 
4. Component Architectural Patterns 
Composition Over Props: Follow shadcn’s pattern of composable sub-components (e.g., <Dialog><DialogContent>...</DialogContent></Dialog>) instead of passing large configuration objects.
The cn Utility: Always use the cn() helper from @/lib/utils for conditional class merging and Tailwind overrides.
Data Tables: For the backoffice, use @tanstack/react-table integrated into a shadcn DataTable component.
Accessibility: Do not remove or override the Radix UI accessibility attributes (ARIA labels, roles) provided by the shadcn primitives. 
5. Directory Structure
Components: resources/js/components/ui (for raw shadcn) and resources/js/components (for custom blocks).
Pages: resources/js/Pages (Inertia views).
Layouts: resources/js/Layouts (Persistent layouts for Booking vs. Backoffice). 

6. Storefront vs. Backoffice Distinction
Storefront Pages (Frontend):
- Purpose: Public-facing content, landing pages, marketing, and registration.
- Design: Modern "regular website" look. Full-width or centered hero sections, landing page features, pricing tables.
- Layout: Use GuestLayout or specialized landing page layouts.
- Domain: Primary domain (e.g., tams.test) and tenant home pages (unauthenticated).

Backoffice Pages (Backend):
- Purpose: Admin panels, dashboard, management tools, and settings.
- Design: Functional, utility-focused. Sidebars for navigation, header for context, tables/forms for data.
- Layout: Use TenantLayout or LandlordLayout.
- Domain: Authenticated sections of both central and tenant domains.

Analysis Requirement:
Before designing or modifying any page, analyze whether it belongs to the Storefront or Backoffice.
- If Storefront: Prioritize aesthetics, conversion, and information hierarchy.
- If Backoffice: Prioritize efficiency, navigation, and data density.
