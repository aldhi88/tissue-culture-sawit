<script src="{{asset('assets/libs/datatables.net/js/jquery.dataTables.min.js')}}"></script>
<script src="{{asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js')}}"></script>
<script>

    // datatables
    var dtTable = $('#myTable').DataTable({
        processing: true,serverSide: true,scrollX: true,pageLength: 25,
        order: [
            [0,'desc']
        ],
        columnDefs: [
            { className: 'text-center', targets: ['_all'] },
        ],
        ajax: "{{ route('nur-transfers.dtIndex') }}",
        columns: [
            { data: 'sample_number_format', name: 'tc_samples.sample_number', orderable:true, searchable:true},
            { data: 'tc_samples.program', name: 'tc_samples.program', orderable:true, searchable:true},
            { data: 'transfer_count', name: 'transfer_count', orderable:false, searchable:false},
            { data: 'transferred', name: 'transferred', orderable:false, searchable:false},
            { data: 'need_transfer_nursery', name: 'need_transfer_nursery', orderable:false, searchable:false},
            { data: 'need_transfer_estate', name: 'need_transfer_estate', orderable:false, searchable:false},

        ],
        initComplete: function () {
                $('#header-filter th').each(function() {
                    var title = $(this).text();
                    var disable = $(this).attr("disable");
                    if(disable!="true"){
                        $(this).html('<input placeholder="'+title+'" type="text" class="form-control column-search px-1 form-control-sm text-center"/>');
                    }
                });
                $('#header-filter').on('keyup', ".column-search",function () {
                    dtTable
                        .column( $(this).parent().index() )
                        .search( this.value )
                        .draw();
                });
            }
    });
</script>
