/*
 * omnibase/video's studio: the resumable upload (Uppy and its tus plugin,
 * to the tusd server behind /files/), and the processing state of a film
 * that is being transcoded. The application imports Uppy and hands it
 * over, so this file needs no package of its own:
 *
 *   import Uppy from '@uppy/core';
 *   import Dashboard from '@uppy/dashboard';
 *   import Tus from '@uppy/tus';
 *   import '@uppy/core/dist/style.min.css';
 *   import '@uppy/dashboard/dist/style.min.css';
 *   import Studio from '../vendor/omnibase/video/assets/studio.js';
 *   Studio.start({ Uppy, Dashboard, Tus });
 *
 * <div data-video-uploader data-endpoint="/files/" data-token="..." data-max-size="..."
 *      data-done="/studio/envoi/__ID__" data-locale="fr"></div>
 *
 * Resuming: Uppy keeps each upload's address in this browser (tus
 * fingerprints); a cut connection retries by itself, a closed tab or a
 * paused upload starts again where it stopped when the same file is added.
 */
var deps = null;

var FR = {
    strings: {
        dropPasteFiles: 'Déposez votre vidéo ici ou %{browseFiles}',
        browseFiles: 'choisissez-la',
        uploadComplete: 'Envoi terminé',
        uploadPaused: 'Envoi en pause',
        resumeUpload: 'Reprendre l’envoi',
        pauseUpload: 'Mettre en pause',
        retryUpload: 'Réessayer',
        cancelUpload: 'Annuler',
        uploading: 'Envoi en cours',
        complete: 'Terminé',
        uploadFailed: 'L’envoi a échoué',
        paused: 'En pause',
        retry: 'Réessayer',
        cancel: 'Annuler',
        done: 'Terminé',
        filesUploadedOfTotal: { 0: '%{complete} fichier sur %{smart_count} envoyé', 1: '%{complete} fichiers sur %{smart_count} envoyés' },
        dataUploadedOfTotal: '%{complete} sur %{total}',
        xTimeLeft: '%{time} restantes',
        uploadXFiles: { 0: 'Envoyer %{smart_count} fichier', 1: 'Envoyer %{smart_count} fichiers' },
        exceedsSize: '%{file} dépasse la taille permise de %{size}',
        youCanOnlyUploadFileTypes: 'Seules les vidéos sont acceptées : %{types}',
        noInternetConnection: 'Pas de connexion internet',
        connectedToInternet: 'Connexion rétablie',
        removeFile: 'Retirer',
        addMore: 'Ajouter',
        back: 'Retour',
        dropHint: 'Déposez votre vidéo ici'
    },
    pluralize: function (n) { return n === 1 ? 0 : 1; }
};

function mountUploader(box) {
    if (box.dataset.ready !== undefined || !deps) return;
    box.dataset.ready = '';
    var uppy = new deps.Uppy({
        id: 'video-' + (box.dataset.replace || 'new'),
        autoProceed: true,
        allowMultipleUploadBatches: true,
        restrictions: {
            maxNumberOfFiles: box.dataset.replace ? 1 : 10,
            maxFileSize: parseInt(box.dataset.maxSize || '0', 10) || null,
            allowedFileTypes: ['video/*', '.mkv', '.mov', '.mp4', '.webm', '.avi', '.m4v']
        },
        meta: { token: box.dataset.token },
        locale: box.dataset.locale === 'fr' ? FR : undefined
    });
    uppy.use(deps.Dashboard, {
        inline: true,
        target: box,
        width: '100%',
        height: 360,
        proudlyDisplayPoweredByUppy: false,
        showProgressDetails: true,
        hideRetryButton: false,
        hidePauseResumeButton: false,
        theme: document.documentElement.classList.contains('theme-dark') ? 'dark' : 'auto',
        note: box.dataset.note || null
    });
    uppy.use(deps.Tus, {
        endpoint: box.dataset.endpoint || '/files/',
        chunkSize: 16 * 1024 * 1024,
        retryDelays: [0, 1000, 3000, 5000, 10000, 20000],
        removeFingerprintOnSuccess: true,
        withCredentials: true
    });
    uppy.on('upload-success', function (file, response) {
        var id = (response && response.uploadURL ? response.uploadURL : '').split('/').filter(Boolean).pop();
        var done = box.dataset.done;
        if (!done || !id) return;
        var list = document.querySelector('[data-video-uploaded]');
        var url = done.replace('__ID__', encodeURIComponent(id));
        if (list) {
            var item = document.createElement('li');
            var link = document.createElement('a');
            link.href = url;
            link.textContent = file.name;
            item.appendChild(link);
            list.appendChild(item);
        }
        if (uppy.getFiles().every(function (f) { return f.progress && f.progress.uploadComplete; }) && box.dataset.redirect !== undefined) {
            window.location.href = url;
        }
    });
    uppy.on('upload-error', function (file, error) {
        var why = box.querySelector('[data-video-upload-error]');
        if (why) why.textContent = (error && error.message) || '';
    });
    box.uppy = uppy;
}

/** A film being transcoded: its state asked every few seconds, the page loaded again once it is ready. */
function watchProcessing(box) {
    if (box.dataset.ready !== undefined) return;
    box.dataset.ready = '';
    var url = box.dataset.videoProcessing;
    var tick = function () {
        if (!document.body.contains(box)) return;
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (state) {
                box.dataset.state = state.processing;
                var label = box.querySelector('[data-video-processing-label]');
                if (label && state.label) label.textContent = state.label;
                if (state.processing === 'ready' || state.processing === 'failed') {
                    if (box.dataset.reload !== undefined) window.location.reload();
                    return;
                }
                setTimeout(tick, 4000);
            })
            .catch(function () { setTimeout(tick, 8000); });
    };
    setTimeout(tick, 2500);
}

/** The stills: a click picks the poster (into the hidden field). */
function posters(root) {
    root.querySelectorAll('[data-video-poster-choice]:not([data-ready])').forEach(function (button) {
        button.setAttribute('data-ready', '');
        button.addEventListener('click', function () {
            var field = document.getElementById(button.dataset.field);
            if (field) field.value = button.dataset.videoPosterChoice;
            root.querySelectorAll('[data-video-poster-choice]').forEach(function (b) { b.setAttribute('aria-pressed', b === button ? 'true' : 'false'); });
        });
    });
}

function scan() {
    document.querySelectorAll('[data-video-uploader]').forEach(mountUploader);
    document.querySelectorAll('[data-video-processing]').forEach(watchProcessing);
    posters(document);
    // The scheduled date only matters for "programmée".
    document.querySelectorAll('[data-video-visibility]').forEach(function (fieldset) {
        var date = document.querySelector(fieldset.dataset.videoVisibility);
        if (!date) return;
        var sync = function () {
            var checked = fieldset.querySelector('input:checked');
            date.closest('.video-field, div').hidden = !(checked && checked.value === 'scheduled');
        };
        fieldset.addEventListener('change', sync);
        sync();
    });
}

var Studio = {
    start: function (dependencies) {
        deps = dependencies || null;
        window.addEventListener('transparent:load', scan);
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan, { once: true });
        else scan();
        return Studio;
    }
};

window.VideoStudio = Studio;
export default Studio;
