<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\ItemCondition;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Payjp\Charge;


class ItemController extends Controller
{

    public function showSellForm()
    {
        $conditions = ItemCondition::orderBy('id')->get();
        $categories = Category::orderBy('id')->get();
        return view('items.create', compact('categories', 'conditions'));
    }

    public function showBuyForm(Item $item)
    {
        return view('items.item_buy_form', compact('item'));
    }

    public function index(Request $request)
    {
        // query 生成
        $query = Item::query();

        if ($request['category']) {
            $query->where('category_id', $request['category'][0]);
        }

        if ($request['condition']) {
            $query->where('condition_id', $request['condition'][0]);
        }

        if ($request['keyword']) {
            $query->where('item_name', 'LIKE', '%' . $request['keyword'][0] . '%');
        }

        if ($request['status']) {
            $query->where('buyer_id', null);
        }

        // Item 取得
        $items = $query->orderBy('created_at', 'desc')->paginate(18);

        // dd($items);

        $categories = Category::orderBy('id')->get();

        $conditions = ItemCondition::orderBy('id')->get();

        return view('items.index', ['items' => $items, 'categories' => $categories, 'conditions' => $conditions]);
    }

    public function create()
    {
        return view('items.store');
    }

    public function sellItem(Request $request)
    {
        $user = Auth::user();

        $this->validate(
            $request,
            [
                'category' => 'required',
                'condition' => 'required',
                'item_name' => 'required|string|max:255',
                'description' => 'required|string|max:10000',
                'price' => 'required|integer|min:1|max:999999',
                'img_src' => 'required|image|file|mimes:png',
            ]
        );

        $img_src = $this->saveItemImg($request->file('img_src'));
        // 商品画像取得

        $item = new Item;
        $item->seller_id = $user->id;
        $item->category_id = $request->category;
        $item->condition_id = $request->condition;
        $item->item_name = $request->item_name;
        $item->img_src = $img_src;
        $item->description = $request->description;
        $item->price = $request->price;

        $item->save();
        return redirect()->back()->with('status', '商品を出品しました');
    }



    // 商品購入
    public function buyItem(Request $request, Item $item)
    {
        $user = Auth::user();

        if ($item->bought_at) {
            abort(404);
        }

        $token = $request->input('card-token');

        try {
            $this->settlement($item->id, $user->id, $token);
        } catch (Exception $e) {
            Log::error($e);
            return redirect()->back()
                ->with('message', '購入処理が失敗しました。');
        }

        return redirect()->route('items.show', $item->id)
            ->with('message', '商品を購入しました。');
    }

    public function getBoughtItems()
    {
        $user = Auth::user();

        $items = $user->boughtItems()->orderBy('bought_at', 'desc')->get();

        return view('mypage.bought-items', compact('items'));
    }

    public function getSoldItems()
    {
        $user = Auth::user();

        $items = $user->soldItems()->orderBy('id', 'desc')->get();

        return view('mypage.sold-items', compact('items'));
    }

    public function getLikedItems()
    {
        $user = Auth::user();

        $items = $user->likedItems()->orderBy('id', 'desc')->get();

        return view('mypage.liked-items', compact('items'));
    }

    private function settlement($itemID, $buyerID, $token)
    {
        DB::beginTransaction();

        try {
            $item = Item::lockForUpdate()->find($itemID);

            $item->bought_at = Carbon::now();
            $item->buyer_id  = $buyerID;
            $item->save();

            $charge = Charge::create([
                'card'     => $token,
                'amount'   => $item->price,
                'currency' => 'jpy'
            ]);
            if (!$charge->captured) {
                throw new Exception('支払い確定失敗');
            }
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }

        DB::commit();
    }

    public function show(Item $item)
    {
        $categories = Category::orderBy('id')->get();
        return view('items.show', ['item' => $item, 'categories' => $categories]);
    }

    public function edit(Item $item)
    {
        $conditions = ItemCondition::all();
        $categories = Category::all();

        return view(
            'items.edit',
            [
                'item' => $item,
                'categories' => $categories,
                'conditions' => $conditions,
            ]
        )->with('message', '商品を編集しました');
    }

    public function update(Request $request, Item $item)
    {
        $this->validate(
            $request,
            [
                'category_id' => 'required',
                'condition_id' => 'required',
                'item_name' => 'required|string|max:255',
                'description' => 'required|string|max:10000',
                'price' => 'required|integer|min:1|max:999999',
            ]
        );

        if ($request->has('img_src')) {
            $filename = $this->saveItemImg($request->file('img_src'));
            $item->img_src = $filename;
        }

        $item->item_name = $request->input('item_name');
        $item->category_id = $request->input('category_id');
        $item->condition_id = $request->input('condition_id');
        $item->description = $request->input('description');
        $item->price = $request->input('price');

        $item->save();

        return redirect(route('items.show', ['item' => $item]))
            ->with('message', '商品を編集しました');
    }

    public function destroy(Item $item)
    {
        $item->delete();
        return redirect(route('items.index'))->with('status', '商品を削除しました');
    }

    // 一時ファイルの作成
    private function makeTempPath()
    {
        $tmp_fp = tmpfile();
        $meta = stream_get_meta_data($tmp_fp);
        return $meta['uri'];
    }

    // プロフィール画像をstorage/public/app/profile_images以下に保存し、ファイル名を返す
    private function saveItemImg(UploadedFile $file)
    {
        $tempPath = $this->makeTempPath();

        ImageManager::imagick()->read($file)->cover(200, 200)->save($tempPath);

        $filePath = Storage::disk('public')
            ->putFile('item_images', new File($tempPath));

        return basename($filePath);
    }
}
