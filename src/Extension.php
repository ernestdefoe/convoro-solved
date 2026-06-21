<?php

namespace Convoro\Ext\Solved;

use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Accepted Answers — first-party Convoro extension.
 *
 * Marks categories as Q&A. In a Q&A topic the asker (or staff) can mark a reply
 * as the accepted answer (topics.solved_post_id). The forum bundle renders the
 * button into the core `post:actions` slot and reads state from this API.
 */
class Extension extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(function () {
            // Per-topic state for the forum bundle: is the category Q&A, which
            // post is the answer, and may the current viewer mark one.
            Route::get('/api/ext/solved/topic/{topic}', function (int $topic) {
                $t = DB::table('topics')->find($topic);
                if (! $t) {
                    return response()->json(['isQa' => false, 'solvedPostId' => null, 'canMark' => false]);
                }

                return response()->json([
                    'isQa' => self::isQa($t),
                    'solvedPostId' => $t->solved_post_id ? (int) $t->solved_post_id : null,
                    'canMark' => self::canMark(Auth::user(), $t),
                ]);
            });
        });

        Route::middleware(['web', 'auth'])->group(function () {
            // Toggle a reply as the accepted answer.
            Route::post('/api/ext/solved/topic/{topic}/post/{post}', function (Request $request, int $topic, int $post) {
                $t = DB::table('topics')->find($topic);
                abort_if(! $t, 404);
                abort_unless(self::canMark($request->user(), $t), 403);
                abort_unless(self::isQa($t), 422);

                $p = DB::table('posts')->where('id', $post)->where('topic_id', $topic)->first();
                abort_if(! $p || $p->is_first, 404);

                $next = ((int) $t->solved_post_id === $post) ? null : $post;
                DB::table('topics')->where('id', $topic)->update(['solved_post_id' => $next]);

                return response()->json(['solvedPostId' => $next]);
            });
        });

        Route::middleware(['web', 'auth', 'admin'])->group(function () {
            Route::get('/admin/ext/solved', fn () => self::adminPage());
            Route::post('/admin/ext/solved', function (Request $request) {
                $ids = collect($request->input('qa', []))->map(fn ($v) => (int) $v)->filter()->all();
                DB::table('categories')->update(['is_qa' => false]);
                if ($ids) {
                    DB::table('categories')->whereIn('id', $ids)->update(['is_qa' => true]);
                }

                return response()->json(['ok' => true, 'count' => count($ids)]);
            });
        });
    }

    /** Is the topic in a Q&A category? */
    private static function isQa(object $topic): bool
    {
        if (! $topic->category_id) {
            return false;
        }

        return (bool) DB::table('categories')->where('id', $topic->category_id)->value('is_qa');
    }

    /** May $user mark/clear the answer? The asker or a moderator/admin. */
    private static function canMark($user, object $topic): bool
    {
        if (! $user) {
            return false;
        }

        return (int) $topic->user_id === (int) $user->id
            || (bool) ($user->is_admin ?? false)
            || (bool) $user->hasPermission('topic.lock');
    }

    private static function adminPage(): \Inertia\Response
    {
        $cats = DB::table('categories')->orderBy('position')->orderBy('name')->get(['id', 'name', 'is_qa']);
        $rows = $cats->map(function ($c) {
            $checked = $c->is_qa ? ' checked' : '';

            return '<label class="aa-row"><span class="aa-nm">'.htmlspecialchars($c->name).'</span>'
                .'<input type="checkbox" data-id="'.$c->id.'"'.$checked.'></label>';
        })->implode('') ?: '<p class="aa-muted">No categories yet — create one in Admin → Categories &amp; Tags first.</p>';

        $body = <<<HTML
        <div class="aa-wrap">
          <h1 class="aa-h1">Accepted Answers</h1>
          <p class="aa-sub">Choose which categories work like Q&amp;A. In those, the person who asked — or your staff — can mark a reply as the accepted answer.</p>
          <div class="aa-card" id="cats">{$rows}</div>
          <div class="aa-actions"><button class="aa-btn" id="save">Save</button><span class="aa-msg" id="msg"></span></div>
        </div>
        HTML;

        $css = <<<'CSS'
        .aa-wrap{max-width:640px;margin:0 auto;padding:32px 24px}
        .ext-embed .aa-wrap{padding:0}
        .aa-h1{font-size:22px;font-weight:800;margin:0 0 4px;color:rgb(var(--c-text))}
        .aa-sub{color:rgb(var(--c-muted));margin:0 0 22px;font-size:14px}
        .aa-card{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:var(--c-radius,12px);padding:2px 18px}
        .aa-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid rgb(var(--c-border))}
        .aa-row:last-child{border-bottom:0}
        .aa-nm{flex:1;font-weight:600;color:rgb(var(--c-text))}
        .aa-muted{color:rgb(var(--c-muted));padding:18px 0;margin:0}
        .aa-card input[type=checkbox]{width:20px;height:20px;accent-color:rgb(var(--c-primary));cursor:pointer}
        .aa-actions{display:flex;align-items:center;gap:14px;margin-top:18px}
        .aa-btn{border:0;border-radius:var(--c-radius-btn,9px);padding:10px 20px;font:inherit;font-weight:700;cursor:pointer;background:rgb(var(--c-primary));color:#fff}
        .aa-btn:hover{background:rgb(var(--c-primary-600,var(--c-primary)))}
        .aa-btn:disabled{opacity:.6;cursor:default}
        .aa-msg{color:rgb(var(--c-muted));font-size:14px}
        CSS;

        $js = <<<'JS'
        var btn=document.getElementById('save'), msg=document.getElementById('msg');
        btn.addEventListener('click', async function () {
          var qa=[].slice.call(document.querySelectorAll('#cats input[type=checkbox]:checked')).map(function(i){return i.dataset.id;});
          btn.disabled=true; msg.textContent='Saving…';
          try {
            var r=await fetch('/admin/ext/solved',{method:'POST',headers:H,body:JSON.stringify({qa:qa})});
            msg.textContent = r.ok ? 'Saved ✓' : 'Could not save.';
            if(r.ok && window.parent!==window){try{window.parent.postMessage({type:'convoro:toast',message:'Saved',kind:'success'},location.origin);}catch(e){}}
          } catch(e){ msg.textContent='Could not save.'; }
          btn.disabled=false;
          setTimeout(function(){ msg.textContent=''; }, 2500);
        });
        JS;

        return \App\Support\ExtPage::render('Accepted Answers', $body, $css, $js);
    }
}
