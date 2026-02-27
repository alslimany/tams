export const useTranslation = () => {
    const t = (key, params = {}) => {
        let translation = key;
        
        // Simple parameter replacement logic
        Object.keys(params).forEach(param => {
            translation = translation.replace(`:${param}`, params[param]);
        });
        
        return translation;
    };

    return { t };
};
