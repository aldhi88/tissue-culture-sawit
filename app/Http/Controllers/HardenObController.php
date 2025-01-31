<?php

namespace App\Http\Controllers;

use App\Models\TcDeath;
use App\Models\TcHarden;
use App\Models\TcHardenOb;
use App\Models\TcHardenObDetail;
use App\Models\TcHardenTree;
use App\Models\TcInit;
use App\Models\TcSample;
use App\Models\TcWorker;
use Illuminate\Http\Request;
use DataTables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HardenObController extends Controller
{
    public function index()
    {
        $data['title'] = "Hardening (Per Sample)";
        $data['desc'] = "Display all observation summary by sample data";
        $data['death'] = TcDeath::all();
        return view('modules.harden_ob.index',compact('data'));
    }
    public function dt(Request $request)
    {
        $data = TcInit::select([
                'tc_inits.id',
                'tc_inits.tc_sample_id',
            ])
            ->with([
                'tc_samples:sample_number,id,program'
            ])
            ->whereHas('tc_hardens')
            ->withCount(['tc_hardens as harden_count' => function($q){
                $q->where('tc_hardens.status',1);
            }])
            ->withCount(['tc_harden_obs as obs_count' => function($q){
                $q->where('tc_harden_obs.status', 1);
            }])
            ->withCount([
                'tc_harden_trees as total_data' => function($q){
                    $q->where('tc_harden_trees.status','!=',0);
                }
            ])
            ->withCount([
                'tc_harden_ob_details as total_transfer' => function($q){
                    $q->where('is_transfer',1)
                       ->whereHas('tc_harden_trees', function($q2){
                           $q2->whereHas('tc_hardens',function($q3){
                               $q3->where('status',1);
                           });
                       });
                }
            ])
            ->withCount([
                'tc_harden_ob_details as total_death' => function($q){
                    $q->where('is_death',1)
                       ->whereHas('tc_harden_trees', function($q2){
                           $q2->whereHas('tc_hardens',function($q3){
                               $q3->where('status',1);
                           });
                       });
                }
            ])
            ->withCount(['tc_harden_trees as harden_active' => function($q){
                $q->where('tc_harden_trees.status', 1);
            }])
        ;
        $dtDeath = TcDeath::all();
        foreach ($dtDeath as $key => $value) {
            $deathId = $value->id;
            $data->withCount([
                'tc_harden_ob_details as total_death_'.$value->id => function($q) use($deathId){
                    $q->where('is_death',1)->where('tc_death_id',$deathId)
                       ->whereHas('tc_harden_trees', function($q2){
                           $q2->whereHas('tc_hardens',function($q3){
                               $q3->where('status',1);
                           });
                       })
                    ;
                }
            ]);
        }

        // dd($data->get()->toArray());
        return DataTables::of($data)
            ->addColumn('sample_number_format',function($data){
                $el = '<p class="mb-0"><strong>'.$data->tc_samples->sample_number_display.'</strong></p>';
                $el .= '
                    <p class="mb-0">
                        <a class="text-primary" href="'.route('harden-obs.show',$data->id).'">Detail</a>
                ';
                $el .= '</p>';
                return $el;
            })
            ->rawColumns(['sample_number_format'])
            ->smart(false)->toJson();
    }

    public function show($id)
    {
        $data['title'] = "Hardening Observation Data";
        $data['desc'] = "Display all harden trees list";
        $data['initId'] = $id;
        $data['sampleNumber'] = TcSample::select('id','sample_number')
            ->whereHas('tc_inits', function(Builder $q) use($id){
                $q->where('id',$id);
            })
            ->first()->getAttribute('sample_number_display');
        $data['death'] = TcDeath::all();
        return view('modules.harden_ob.show',compact('data'));
    }

    public function dtShow(Request $request)
    {
        $data = TcHarden::select([
                'tc_hardens.*',
                DB::raw('convert(varchar,tree_date, 103) as tree_date_format'),
            ])
            ->where('tc_init_id',$request->initId)
            ->with([
                'tc_inits:id,tc_sample_id',
                'tc_inits.tc_samples' => function($q){
                    $q->select('id','program','sample_number');
                },
                'tc_workers:id,code',
            ])
            ->withCount([
                'tc_harden_trees as total_data' => function($q){
                    $q->where('status','!=',0);
                }
            ])
            ->withCount(['tc_harden_trees as total_active' => function($q){
                $q->where('status',1);
            }])
            ->withCount([
                'tc_harden_obs as total_obs' => function($q){
                    $q->where('status',1);
                }
            ])
            ->withCount([
                'tc_harden_ob_details as total_transfer' => function($q){
                    $q->where('is_transfer',1);
                }
            ])
            ->withCount([
                'tc_harden_ob_details as total_death' => function($q){
                    $q->where('is_death',1);
                }
            ])
        ;
        if($request->filter == 1 || !isset($request->filter)){
            $data->where('tc_hardens.status','!=',0);
        }

        $dtDeath = TcDeath::all();
        foreach ($dtDeath as $key => $value) {
            $deathId = $value->id;
            $data->withCount([
                'tc_harden_ob_details as total_death_'.$value->id => function($q) use($deathId){
                    $q->where('is_death',1)->where('tc_death_id',$deathId);
                }
            ]);
        }

        $initId = $request->initId;
        return DataTables::of($data)
            ->filterColumn('tree_date_format', function($query, $keyword) {
                $sql = 'convert(varchar,tree_date, 103) like ?';
                $query->whereRaw($sql, ["{$keyword}"]);
            })
            ->addColumn('tree_date_action',function($data) use($initId){
                $el = '<p class="mb-0"><strong>'.$data->tree_date_format.'</strong></p>';
                $q = TcHardenOb::where('tc_harden_id',$data->id)->where('status',0)->get();
                if($q->count() == 0){
                    $dt['tc_init_id'] = $initId;
                    $dt['tc_harden_id'] = $data->id;
                    $q = TcHardenOb::create($dt);unset($dt);
                    $obId = $q->id;
                }else{
                    $obId = $q->first()->id;
                }

                if($data->total_active != 0){
                    $el .= '
                        <a class="text-primary detail fs-13" data-date="'.$data->tree_date_format.'" data-id="'.$data->id.'" href="'.route('harden-obs.create',$obId).'">Obs</a> -
                    ';
                }

                $el .= '
                    <a class="text-primary detail fs-13" data-date="'.$data->tree_date_format.'" data-id="'.$data->id.'" href="#'.$data->id.'">Detail</a>
                ';
                return $el;
            })
            ->rawColumns(['tree_date_action'])
            ->smart(false)->toJson();
    }

    public function dtShow2(Request $request)
    {
        $hardenId = $request->filter;

        $data = TcHardenOb::select([
                'tc_harden_obs.*',
                DB::raw('convert(varchar,ob_date, 103) as ob_date_format'),
            ])
            ->where('tc_harden_id',$hardenId)
            ->where('status',1)
            ->with([
                'tc_hardens'
            ])
            ->withCount([
                'tc_harden_ob_details as total_transfer' => function($q){
                    $q->where('is_transfer',1);
                }
            ])
            ->withCount([
                'tc_harden_ob_details as total_death' => function($q){
                    $q->where('is_death',1);
                }
            ])
        ;

        $dtDeath = TcDeath::all();
        foreach ($dtDeath as $key => $value) {
            $deathId = $value->id;
            $data->withCount([
                'tc_harden_ob_details as total_death_'.$value->id => function($q) use($deathId){
                    $q->where('is_death',1)->where('tc_death_id',$deathId);
                }
            ]);
        }
        return DataTables::of($data)
            ->addColumn('tree_date_format',function($data){
                return Carbon::parse($data->tc_hardens->tree_date)->format('d/m/Y');
            })
            ->filterColumn('ob_date_format', function($query, $keyword) {
                $sql = 'convert(varchar,ob_date, 103) like ?';
                $query->whereRaw($sql, ["{$keyword}"]);
            })
            ->addColumn('ob_date_action',function($data){
                $el = '<p class="mb-0"><strong>'.$data->ob_date_format.'</strong></p>';
                $el .= '
                    <a class="text-primary detail fs-13" href="'.route('harden-obs.create',$data->id).'">Edit</a>
                ';
                $q = TcHardenObDetail::where('tc_harden_ob_id',$data->id)->get()->count();
                if($q==0){
                    $el .= " - <a class='text-danger fs-13' href='#delModal' data-toggle='modal' data-target='#delModal' data-attr='".Carbon::parse($data->ob_date)->format('d/m/Y')."' data-id='".$data->id."'>Delete</a>";
                }
                return $el;
            })
            ->rawColumns(['ob_date_action'])
            ->smart(false)->toJson();
    }

    public function create($obsId)
    {
        $data['title'] = "Hardening Observation";
        $data['desc'] = "Display acclimatization observation form";
        $data['workers'] = TcWorker::where('status',1)->get();
        $qObs = TcHardenOb::where('id',$obsId)->with('tc_inits')->first();
        $data['initId'] = $qObs->tc_init_id;
        $data['sample'] = $qObs->tc_inits->tc_samples->sample_number_display;
        $data['worker_now'] = $qObs->tc_worker_id;
        $data['date_ob'] = is_null($qObs->ob_date)?false:Carbon::parse($qObs->ob_date)->format('Y-m-d');
        $data['obsId'] = $obsId;
        $data['start'] = $qObs->status==0?false:true;
        $data['tc_harden_id'] = $qObs->tc_harden_id;

        $collect = collect(TcHardenObDetail::where('tc_harden_ob_id',$qObs->id)->get()->toArray());
        $treeCount = TcHardenTree::where('status','!=',0)->where('tc_harden_id',$data['tc_harden_id'])->get()->count();
        $data['isCheck'] = null;
        if($treeCount == $collect->count()){
            if($collect->where('is_death',1)->count() == 0){
                $data['isCheck'] = 'checked';
            }
        }
        return view('modules.harden_ob.create',compact('data'));
    }

    public function store(Request $request)
    {
        $data['tc_worker_id'] = $request->tc_worker_id;
        $data['ob_date'] = $request->date_ob;
        $data['status'] = 1;
        TcHardenOb::where('id',$request->id)->update($data);
        $q = TcHardenOb::where('tc_harden_id',$request->tc_harden_id)->where('status',0)->get()->count();
        if($q == 0){
            TcHardenOb::create([
                'tc_init_id' => $request->tc_init_id,
                'tc_harden_id' => $request->tc_harden_id
            ]);
        }
        return response()->json([
            'status' => 'success',
            'data' => [
                'type' => 'success',
                'icon' => 'check',
                'el' => 'alert-area',
                'msg' => 'Success, observation data has been created.',
                'status' => 'update',
                'tc_worker_id' => $request->tc_worker_id,
                'obs_date' => $request->date_ob,
            ],
        ]);
    }

    public function dtObs(Request $request)
    {
        $hardenId = $request->hardenId;
        $obsId = $request->obsId;
        $data = TcHardenTree::select([
                'tc_harden_trees.*',
                DB::raw('convert(varchar,tc_hardens.tree_date, 103) as tree_date_format'),
            ])
            ->where('tc_harden_id',$hardenId)
            ->where('tc_harden_trees.status','!=',0)
            ->whereDoesntHave('tc_harden_ob_details', function(Builder $q) use($obsId){
                $q->where('tc_harden_ob_id','!=',$obsId);
            })
            ->leftJoin('tc_hardens','tc_hardens.id','=','tc_harden_trees.tc_harden_id');
        return DataTables::of($data)
            ->addColumn('death_form',function($data) use($obsId){
                $deathId = null;
                $cek = TcHardenObDetail::where('tc_harden_tree_id',$data->id)
                    ->where('tc_harden_ob_id',$obsId)->get();
                if(count($cek)!=0 && $cek->first()->is_death == 1){
                    $deathId = $cek->first()->tc_death_id;
                }
                $dtDeath = TcDeath::all();
                $el = '<select name="" class="death-pick w-100 text-center" data-id="'.$data->id.'">';
                $el .= '<option value="0">- Choose -</option>';
                foreach ($dtDeath as $key => $value) {
                    $check = $deathId == $value->id?'selected':null;
                    $el .= '<option '.$check.' value="'.$value->id.'">'.$value->code.'</option>';
                }
                $el .= '</select>';
                return $el;
            })
            ->addColumn('transfer_form',function($data) use($obsId){
                $q = TcHardenObDetail::where('tc_harden_ob_id',$obsId)
                    ->where('tc_harden_tree_id',$data->id)->get();
                $el = '<input type="checkbox" class="item" value="'.$data->id.'" id="item-'.$data->id.'">';
                if(count($q)!=0){
                    if($q->first()->is_transfer == 1){
                        $el = '<input checked type="checkbox" class="item" id="item-'.$data->id.'" value="'.$data->id.'">';
                    }
                }
                return $el;
            })
            ->addColumn('pre_nursery_form',function($data) use($obsId){
                $q = TcHardenObDetail::where('tc_harden_ob_id',$obsId)
                    ->where('tc_harden_tree_id',$data->id)->get();
                $el = '<input type="date" name="pre_nursery" class="form-control form-control-sm item-'.$data->id.'" value="'.date('Y-m-d').'" data-id="'.$data->id.'">';
                if(count($q)!=0){
                    if($q->first()->is_transfer == 1){
                        $el = '<input type="date" name="pre_nursery" class="form-control form-control-sm item-'.$data->id.'" value="'.Carbon::parse($q[0]->pre_nursery)->format('Y-m-d').'" data-id="'.$data->id.'">';
                    }
                }
                return $el;
            })
            ->rawColumns(['status_format','death_form','transfer_form','pre_nursery_form'])
            ->smart(false)->toJson();
    }

    public function storeDetail(Request $request)
    {
        if($request->isTransfer == 1 || $request->isDeath == 1){
            $q = TcHardenObDetail::where('tc_harden_ob_id',$request->obsId)
                ->where('tc_harden_tree_id',$request->id)->get();
            if(count($q)==0){
                $data = [
                    'tc_init_id' => $request->initId,
                    'tc_harden_tree_id' => $request->id,
                    'tc_harden_ob_id' => $request->obsId,
                    'is_death' => $request->isDeath,
                    'pre_nursery' => $request->pre,
                    'is_transfer' => $request->isTransfer,
                ];
                if($request->isDeath == 1){
                    $data['tc_death_id'] = $request->deathId;
                }
                TcHardenObDetail::create($data);
            }else{
                $data = [
                    'is_transfer' => $request->isTransfer,
                    'is_death' => $request->isDeath,
                    'pre_nursery' => $request->pre,
                ];
                if($request->isDeath == 1){
                    $data['tc_death_id'] = $request->deathId;
                }
                TcHardenObDetail::where('tc_harden_ob_id',$request->obsId)
                    ->where('tc_harden_tree_id',$request->id)->update($data);
            }
            $q = TcHardenTree::where('id',$request->id)->first();
            $q->update(['status' => 2]);
        }else{
            TcHardenObDetail::where('tc_harden_ob_id',$request->obsId)
                ->where('tc_harden_tree_id',$request->id)->forceDelete();
            $q = TcHardenTree::where('id',$request->id)->first();
            $q->update(['status' => 1]);
        }
        TcHardenOb::genObsResult($request->obsId);
    }

    public function storeDetailAll(Request $request)
    {
        $treeId = array_column(TcHardenObDetail::where('tc_harden_ob_id',$request->obsId)->get()->toArray(),'tc_harden_tree_id');
        TcHardenObDetail::where('tc_harden_ob_id',$request->obsId)->forceDelete();
        TcHardenTree::whereIn('id',$treeId)->update(['status' => 1]);
        $q = TcHardenTree::where('status',1)->where('tc_harden_id',$request->hardenId)->get();
        if($request->action == 1){
            foreach ($q as $key => $value) {
                $data[] = [
                    'tc_init_id' => $request->initId,
                    'tc_harden_tree_id' => $value->id,
                    'tc_harden_ob_id' => $request->obsId,
                    'is_transfer' => 1,
                    'pre_nursery' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            }
            TcHardenObDetail::insert($data);
            TcHardenTree::where('tc_harden_id',$request->hardenId)->update(['status' => 2]);
        }
        TcHardenOb::genObsResult($request->obsId);
    }
    public function destroy($id)
    {
        TcHardenOb::where('id',$id)->forceDelete();
        return alert(1,null,'alert-area2');
    }
}
