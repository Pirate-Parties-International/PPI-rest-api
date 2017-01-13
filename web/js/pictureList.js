(function($) {
    var app = angular.module('app', ['infinite-scroll']); 
    //directive that cheks if an image is loaded
    /*app.directive('imageonload', function() {
        return {
            restrict: 'A',
            link: function(scope, element, attrs) {
                element.bind('load', function() {
                    return true;
                });
                element.bind('error', function(){
                    return false;
                });
            }
        };
    });*/

    app.controller('pictureController', ['$scope', 'pictureAndPostFactory', function($scope, pictureAndPostFactory) {
        //URL that gets all socialmedia posts that are (primarily) pictures
        $scope.data =[];
        $scope.masterArray =[];
        $scope.originalArray =[]; // The purpose of this array is to store the original layout of the masterArray
        $scope.address = 'http://api.piratetimes.net/api/v1/social/?_format=json&sub_type=I&'
        
        
        //this functions loads more data for the infinite scroll
        // it constantily updates the array from which ng-repeat gets its data
        $scope.loadData = function(address) {
            //Function that gets the data 
            //it transforms the object into an array and runs
            console.log("test")
            $scope.loading = true;
            pictureAndPostFactory.imageList(address).then(function(successResponse){
                
                $scope.masterArray = $scope.originalArray.concat(successResponse);
                console.log($scope.masterArray);
                $scope.originalArray = $scope.masterArray.slice();
                $scope.loadMore(address);
                $scope.loading = false;
            });   
        };
        $scope.loadMore = function(){
            if ($scope.masterArray.length == 0){
                console.log("is inside first IF")
                $scope.loadData($scope.address);
                infiniteArray()
            } else {
                infiniteArray()
                offset($scope.address);
            }
        };

        function infiniteArray(){
            var last = [];
            var x
            if ( $scope.data.length == 0){
                x = 0
            }
            else {
                x = $scope.data.length - 1;
            }
            for(var i = 1; i <= 20; i++) {
                //check if the element is undefined, then there is no more data and the fuction can stop
                var currentValue = $scope.masterArray[x+i];
                if (currentValue == undefined){
                    return
                } else {      
                    $scope.data.push(currentValue);
                }
            };
        }

        function offset(address){
            //we get 100 pieces of data at a time, this checks, if we need to get more
            //it first checks if offset is already present in the address and if it is, it removes the offset number
            //and replaces it with a new one    
            if (($scope.data.length%100)===0){
                if (($scope.masterArray.length/100)==1){
                    address = address + "offset=100"
                    $scope.address += address;
                    console.log("saved Address je 1 " + address);
                }
                else {
                    console.log(address)
                    address = address.substring(0, address.lastIndexOf("=")-1);
                    console.log(address)
                    address = address + $scope.masterArray.length;
                    console.log(address)
                    $scope.address += address;
                    console.log(address);
                };
                $scope.loadData($scope.address); 
            }; 
        }


        //using a factory gets a list of all pirate parties using $http.get and it transforms the object into array of objects

        pictureAndPostFactory.partyList().then(function(successResponse){
            $scope.partyList = Object.values(successResponse)
        });
        

        //ensure that you can click anywhere inside the li to check the dropdown radio button
        $(".party-dropdown").click(function(){
            $("this > input").attr("checked", "checked")
        })

        $scope.emptyInput = function(){
            $scope.partySelection = "";
        }

        //function that sorts entries by reach in ascending order
        //currently not available
        /*$scope.sortAscViews = function(){ 
            $scope.masterArray.sort(function(a, b){
            return a.post_likes > b.post_likes;
        });
        $scope.data = [];
        loadData();
        };*/

        //function that sorts entries by reach in descending order
        $scope.sortDescViews = function(){
        $scope.data = [];
        $scope.loadMore("&order_by=likes");
        };

        $scope.defaultSort = function(){
        $scope.masterArray = $scope.originalArray.slice(); 
        $scope.data = [];
        $scope.loadMore(""); 
        $(".up").removeClass("arrow-color")
        $(".down").removeClass("arrow-color")
        }

        //function that toggles between ascending and descending amount of reach
        //the API currently doesn't support descending/ascending sort,so this code is pointless
        //If it is added, uncomment and change the function called by the element with ID asc-desc-views
        /*$scope.sortByViews = function(){
            if ( $("#asc-desc-views").hasClass("desc") ) {
                $("#asc-desc-views").removeClass("desc")
                $("#asc-desc-views").addClass("asc")
                $scope.sortAscViews()       
            }*/
            //same reason as above
           /* else {
                $("#asc-desc-views").addClass("toggled")
                $("#asc-desc-views").addClass("desc")
                $("#asc-desc-views").removeClass("asc")
                $scope.sortDescViews();
            };
        }*/

        //stops dropdown from closing
        $('#platform-selection').bind('click', function (e) { e.stopPropagation() })
        $('#party-selection').bind('click', function (e) { e.stopPropagation() })

    }]);
})(jQuery); // end of jQuery name space