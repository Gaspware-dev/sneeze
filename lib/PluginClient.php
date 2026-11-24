<?php

declare(strict_types=1);

/**
 * Browser bridge passed to a studio script.
 *
 * A script declared by workspace() or sideTab() is an ES module. Its default
 * export is boot(sneeze). PluginClient::reference() describes that object.
 */
class PluginClient
{
    /**
     * @return list<array{name: string, summary: string, signature: string}>
     */
    public static function reference(): array
    {
        return [
            [
                'name' => 'boot',
                'summary' => 'Default export of the module named by a workspace or side tab script.',
                'signature' => 'export default function boot(sneeze): void | Promise<void>',
            ],
            [
                'name' => 'sneeze.api',
                'summary' => 'Fetch helper used by the studio. Sends the CSRF header on requests that are not GET.',
                'signature' => 'api(path: string, options?: { method?: string, body?: string | FormData, headers?: object }): Promise<any>',
            ],
            [
                'name' => 'sneeze.plugin',
                'summary' => 'The plugin that registered the script.',
                'signature' => '{ id: string, name: string }',
            ],
            [
                'name' => 'sneeze.studio.getState',
                'summary' => 'JSON copy of the open page, the selected block id, templates, and block types.',
                'signature' => '(): { page: object | null, selectedId: number | string | null, templates: object[], blockTypes: object[] }',
            ],
            [
                'name' => 'sneeze.studio.applyPage',
                'summary' => 'Merge a partial into the open draft, refresh the preview, and mark the draft unsaved. The studio autosave writes it.',
                'signature' => '(partial: { title?: string, slug?: string, seo_title?: string, seo_description?: string, template_id?: number, custom_css?: string, custom_js?: string, header_mode?: string, footer_mode?: string, header_html?: string, footer_html?: string, disabled_stylesheet_ids?: number[], disabled_script_ids?: number[], blocks?: object[] }): void',
            ],
            [
                'name' => 'sneeze.studio.openPage',
                'summary' => 'Open a page in the studio by id.',
                'signature' => '(id: number): Promise<void>',
            ],
            [
                'name' => 'sneeze.studio.notify',
                'summary' => 'Show a toast in the studio.',
                'signature' => '(message: string, isError?: boolean): void',
            ],
            [
                'name' => 'sneeze.studio.on',
                'summary' => 'Subscribe to studio events. page receives the opened page, selection receives {id, block}, saved receives the page after a draft save. Returns an unsubscribe function.',
                'signature' => '(event: "page" | "selection" | "saved", fn: (payload: any) => void): () => void',
            ],
        ];
    }
}
