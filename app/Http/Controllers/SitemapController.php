<?php

namespace App\Http\Controllers;

use App\Models\Admindomain;
use App\Models\WikiPage;
use App\Models\Template;
use App\Models\EzFunnelField;
use App\Models\EzFunnel;
use App\Models\Customdomain;
use App\Models\Domain;
use App\Support\SlugAccess;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index()
    {
		$admindomain=Admindomain::where('status','Active')->get();
        // Static Pages
        $staticUrls = [
            '',
            '/video-remix',
            '/home',
            '/funnel-content-oki',
            '/privacy-policy',
            '/terms-and-conditions',
            '/marketplace',
			'/processmp4',
			'/template-marketplace',
			'/register',
			'/login',
			'/forgot-password',
			'/login/magic-link',
        ];
		$wiki=WikiPage::where('status','published')->get();
		$template=Template::get();
		// EZ-LOCK-20261017 P0-8: never list a handle / card / custom domain / subdomain that shows a
		// private or hidden slug (was: every row, so private X0000000 handles were listed on every domain).
		$privateFunnels = SlugAccess::nonPublicFunnelIds();
		$ezfunnelfield = SlugAccess::whereNotInOrNull(EzFunnelField::query(), 'ez_funnel_id', $privateFunnels)->get();
		$ezfunnel = SlugAccess::whereNotInOrNull(EzFunnel::query(), 'aiid', SlugAccess::nonPublicMessageIds())->get();
		$customdomain = SlugAccess::whereNotInOrNull(
			SlugAccess::whereNotInOrNull(Customdomain::query(), 'funnelid', SlugAccess::nonPublicFunnelIds()),
			'aiid', SlugAccess::nonPublicMessageIds()
		)->get();
		$domain = SlugAccess::whereNotInOrNull(Domain::query(), 'funnelid', SlugAccess::nonPublicFunnelIds())->get();
        $content = view('sitemap', [
			'admindomain' => $admindomain,
            'staticUrls' => $staticUrls,
			'wiki' => $wiki,
			'template' => $template,
			'ezfunnelfield' => $ezfunnelfield,
			'ezfunnel' => $ezfunnel,
			'customdomain' => $customdomain,
			'domain' => $domain,
        ])->render();

        // short cache so a slug made private drops out quickly
        return response($content, 200)->header('Content-Type', 'text/xml')->header('Cache-Control', 'public, max-age=300');
    }
	
}